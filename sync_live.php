<?php
// sync_live.php
// How live points work on the FREE API plan:
// - /fixtures?league=202&season=2026 is blocked
// - /fixtures?date=YYYY-MM-DD works for ~today (yesterday / today / tomorrow)
// - We keep Tunisia Ligue 1 matches from that list
// - /fixtures/events works → goals, assists, cards, subs, own goals, missed pens
// - /fixtures/lineups is often empty until close to kickoff; minutes use lineups when present
//
// Run every few minutes on a matchday:
//   C:\xampp\php\php.exe sync_live.php
//
// This does NOT import old 2026 rounds (the free plan refuses past dates).
// Upgrade the API plan if you need the full season history in one shot.

require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/football_api.php';
require_once __DIR__ . '/backend/scoring.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$clubsByApi = [];
foreach ($pdo->query('SELECT id, api_id, name, short_name FROM clubs') as $row) {
    $clubsByApi[(int) $row['api_id']] = $row;
}

$playersByApi = [];
foreach ($pdo->query('SELECT id, api_id, club_id, name, position FROM players WHERE api_id IS NOT NULL') as $row) {
    $playersByApi[(int) $row['api_id']] = $row;
}

function tunisTime(string $iso): string
{
    $dt = new DateTime($iso);
    $dt->setTimezone(new DateTimeZone('Africa/Tunis'));
    return $dt->format('Y-m-d H:i:s');
}

function roundNumber(?string $round): ?int
{
    if ($round && preg_match('/(\d+)\s*$/', $round, $m)) {
        return (int) $m[1];
    }
    return null;
}

function ensureGameweek(PDO $pdo, int $number, string $firstKickoff): array
{
    $deadline = (new DateTime($firstKickoff))->modify('-90 minutes')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('SELECT * FROM gameweeks WHERE number = ?');
    $stmt->execute([$number]);
    $gw = $stmt->fetch();

    if (!$gw) {
        $ins = $pdo->prepare('INSERT INTO gameweeks (number, deadline, status) VALUES (?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        $status = ($deadline > $now) ? 'upcoming' : 'open';
        $ins->execute([$number, $deadline, $status]);
        $stmt->execute([$number]);
        return $stmt->fetch();
    }

    if ($deadline < $gw['deadline']) {
        $pdo->prepare('UPDATE gameweeks SET deadline = ? WHERE id = ?')->execute([$deadline, $gw['id']]);
        $gw['deadline'] = $deadline;
    }
    return $gw;
}

function upsertMatch(PDO $pdo, array $gw, array $home, array $away, array $row): int
{
    $apiId   = (int) $row['fixture']['id'];
    $kickoff = tunisTime($row['fixture']['date']);
    $short   = $row['fixture']['status']['short'] ?? 'NS';
    $status  = in_array($short, ['FT', 'AET', 'PEN'], true) ? 'finished' : 'scheduled';
    $homeScore = $row['goals']['home'];
    $awayScore = $row['goals']['away'];

    $stmt = $pdo->prepare('SELECT id FROM matches WHERE api_id = ?');
    $stmt->execute([$apiId]);
    $id = $stmt->fetchColumn();

    if ($id) {
        $pdo->prepare(
            'UPDATE matches SET gameweek_id = ?, home_club_id = ?, away_club_id = ?,
                    kickoff = ?, home_score = ?, away_score = ?, status = ?
             WHERE id = ?'
        )->execute([
            $gw['id'], $home['id'], $away['id'], $kickoff, $homeScore, $awayScore, $status, $id,
        ]);
        return (int) $id;
    }

    $pdo->prepare(
        'INSERT INTO matches (gameweek_id, home_club_id, away_club_id, kickoff, home_score, away_score, status, api_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $gw['id'], $home['id'], $away['id'], $kickoff, $homeScore, $awayScore, $status, $apiId,
    ]);
    return (int) $pdo->lastInsertId();
}

function emptyStat(): array
{
    return [
        'minutes' => 0, 'goals' => 0, 'assists' => 0, 'yellow_cards' => 0,
        'red_cards' => 0, 'own_goals' => 0, 'missed_penalties' => 0, 'clean_sheet' => 0,
        'team_api_id' => null, 'name' => '',
    ];
}

function statsFromLineupsAndEvents(array $lineups, array $events): array
{
    $stats = [];
    $endMinute = 90;

    foreach ($events as $e) {
        $elapsed = (int) ($e['time']['elapsed'] ?? 0) + (int) ($e['time']['extra'] ?? 0);
        if ($elapsed > $endMinute) {
            $endMinute = $elapsed;
        }
    }

    foreach ($lineups as $teamBlock) {
        $teamApiId = (int) ($teamBlock['team']['id'] ?? 0);
        foreach (['startXI' => true, 'substitutes' => false] as $key => $isStarter) {
            foreach ($teamBlock[$key] ?? [] as $row) {
                $p = $row['player'] ?? [];
                $id = (int) ($p['id'] ?? 0);
                if ($id === 0) {
                    continue;
                }
                if (!isset($stats[$id])) {
                    $stats[$id] = emptyStat();
                }
                $stats[$id]['team_api_id'] = $teamApiId;
                $stats[$id]['name'] = (string) ($p['name'] ?? '');
                $stats[$id]['minutes'] = $isStarter ? $endMinute : 0;
                $stats[$id]['on'] = $isStarter ? 0 : null;
                $stats[$id]['off'] = $isStarter ? $endMinute : 0;
            }
        }
    }

    foreach ($events as $e) {
        $type   = $e['type'] ?? '';
        $detail = $e['detail'] ?? '';
        $minute = (int) ($e['time']['elapsed'] ?? 0);
        $pid    = (int) ($e['player']['id'] ?? 0);
        $aid    = (int) ($e['assist']['id'] ?? 0);
        $teamApiId = (int) ($e['team']['id'] ?? 0);

        if ($pid > 0 && !isset($stats[$pid])) {
            $stats[$pid] = emptyStat();
            $stats[$pid]['name'] = (string) ($e['player']['name'] ?? '');
            $stats[$pid]['team_api_id'] = $teamApiId;
        }
        if ($aid > 0 && !isset($stats[$aid])) {
            $stats[$aid] = emptyStat();
            $stats[$aid]['name'] = (string) ($e['assist']['name'] ?? '');
            $stats[$aid]['team_api_id'] = $teamApiId;
        }

        if ($type === 'subst') {
            // player = off, assist = on
            if ($pid > 0) {
                $stats[$pid]['off'] = $minute;
                $on = $stats[$pid]['on'] ?? 0;
                $stats[$pid]['minutes'] = max(0, $minute - (int) $on);
                $stats[$pid]['team_api_id'] = $teamApiId;
            }
            if ($aid > 0) {
                $stats[$aid]['on'] = $minute;
                $stats[$aid]['off'] = $endMinute;
                $stats[$aid]['minutes'] = max(0, $endMinute - $minute);
                $stats[$aid]['team_api_id'] = $teamApiId;
                $stats[$aid]['name'] = (string) ($e['assist']['name'] ?? $stats[$aid]['name']);
            }
            continue;
        }

        if ($pid === 0) {
            continue;
        }
        $stats[$pid]['team_api_id'] = $teamApiId ?: $stats[$pid]['team_api_id'];

        if ($type === 'Goal') {
            if ($detail === 'Own Goal') {
                $stats[$pid]['own_goals']++;
            } elseif ($detail === 'Missed Penalty') {
                $stats[$pid]['missed_penalties']++;
            } else {
                $stats[$pid]['goals']++;
                if ($aid > 0 && $detail !== 'Penalty') {
                    $stats[$aid]['assists']++;
                    $stats[$aid]['team_api_id'] = $teamApiId ?: $stats[$aid]['team_api_id'];
                }
            }
        } elseif ($type === 'Card') {
            if ($detail === 'Yellow Card') {
                $stats[$pid]['yellow_cards']++;
            } elseif ($detail === 'Red Card' || $detail === 'Second Yellow Card') {
                $stats[$pid]['red_cards']++;
                $on = $stats[$pid]['on'] ?? 0;
                $stats[$pid]['minutes'] = max(1, $minute - (int) $on);
                $stats[$pid]['off'] = $minute;
            }
        }
    }

    foreach ($stats as $id => $s) {
        if (($s['minutes'] ?? 0) === 0 && ($s['goals'] + $s['assists'] + $s['yellow_cards'] + $s['red_cards'] + $s['own_goals'] + $s['missed_penalties']) > 0) {
            $stats[$id]['minutes'] = 1;
        }
        unset($stats[$id]['on'], $stats[$id]['off']);
    }

    return $stats;
}

function savePlayerStats(PDO $pdo, int $matchId, array $stats, array &$playersByApi, array $clubsByApi, int $homeApi, int $awayApi, $homeScore, $awayScore): void
{
    $homeCs = ((int) $homeScore === 0 && $homeScore !== null) ? false : null;
    // clean sheet = team conceded 0
    $homeConceded0 = ($awayScore !== null && (int) $awayScore === 0);
    $awayConceded0 = ($homeScore !== null && (int) $homeScore === 0);

    $upsert = $pdo->prepare(
        'INSERT INTO player_stats
            (match_id, player_id, minutes, goals, assists, yellow_cards, red_cards, own_goals, missed_penalties, clean_sheet)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            minutes = VALUES(minutes), goals = VALUES(goals), assists = VALUES(assists),
            yellow_cards = VALUES(yellow_cards), red_cards = VALUES(red_cards),
            own_goals = VALUES(own_goals), missed_penalties = VALUES(missed_penalties),
            clean_sheet = VALUES(clean_sheet)'
    );

    $insertPlayer = $pdo->prepare(
        'INSERT INTO players (club_id, name, position, price, api_id, is_active)
         VALUES (?, ?, ?, 5.0, ?, 1)'
    );

    foreach ($stats as $apiId => $s) {
        $apiId = (int) $apiId;
        if ($apiId === 0) {
            continue;
        }
        if (($s['minutes'] ?? 0) <= 0 && ($s['goals'] + $s['assists'] + $s['yellow_cards'] + $s['red_cards'] + $s['own_goals'] + $s['missed_penalties']) === 0) {
            continue;
        }

        if (!isset($playersByApi[$apiId])) {
            $clubApi = (int) ($s['team_api_id'] ?? 0);
            if (!isset($clubsByApi[$clubApi])) {
                continue;
            }
            $insertPlayer->execute([$clubsByApi[$clubApi]['id'], $s['name'] ?: ('Player ' . $apiId), 'MID', $apiId]);
            $playersByApi[$apiId] = [
                'id' => (int) $pdo->lastInsertId(),
                'api_id' => $apiId,
                'club_id' => $clubsByApi[$clubApi]['id'],
                'name' => $s['name'],
                'position' => 'MID',
            ];
            echo "  added unknown player {$s['name']} (api {$apiId})\n";
        }

        $teamApi = (int) $s['team_api_id'];
        $cs = 0;
        if ($teamApi === $homeApi && $homeConceded0 && $s['minutes'] >= 60) {
            $cs = 1;
        }
        if ($teamApi === $awayApi && $awayConceded0 && $s['minutes'] >= 60) {
            $cs = 1;
        }
        $s['clean_sheet'] = $cs;

        $upsert->execute([
            $matchId,
            $playersByApi[$apiId]['id'],
            min(255, (int) $s['minutes']),
            $s['goals'],
            $s['assists'],
            $s['yellow_cards'],
            $s['red_cards'],
            $s['own_goals'],
            $s['missed_penalties'],
            $cs,
        ]);
    }
}

function refreshGameweekPoints(PDO $pdo, int $gameweekId, bool $allowStar): void
{
    $rows = $pdo->prepare(
        'SELECT p.id AS player_id, p.position, ps.*, m.home_club_id, m.away_club_id, m.home_score, m.away_score, p.club_id
         FROM player_stats ps
         JOIN matches m ON m.id = ps.match_id
         JOIN players p ON p.id = ps.player_id
         WHERE m.gameweek_id = ?'
    );
    $rows->execute([$gameweekId]);

    $totals = [];
    foreach ($rows as $r) {
        $pid = (int) $r['player_id'];
        $teamCs = ((int) $r['clean_sheet'] === 1);
        $pts = ftlPointsFromStats($r['position'], $r, $teamCs);
        if (!isset($totals[$pid])) {
            $totals[$pid] = 0;
        }
        $totals[$pid] += $pts;
    }

    $stars = [];
    if ($allowStar && $totals) {
        $max = max($totals);
        $candidates = array_keys(array_filter($totals, fn ($v) => $v === $max));
        if (count($candidates) === 1) {
            $stars = $candidates;
        } else {
            $own = $pdo->prepare(
                'SELECT sp.player_id, COUNT(*) AS owners
                 FROM squad_players sp
                 JOIN squads s ON s.id = sp.squad_id
                 WHERE s.gameweek_id = ? AND sp.player_id IN (' . implode(',', array_map('intval', $candidates)) . ')
                 GROUP BY sp.player_id'
            );
            $own->execute([$gameweekId]);
            $counts = [];
            foreach ($own as $o) {
                $counts[(int) $o['player_id']] = (int) $o['owners'];
            }
            foreach ($candidates as $c) {
                if (!isset($counts[$c])) {
                    $counts[$c] = 0;
                }
            }
            $minOwn = min($counts);
            $stars = array_keys(array_filter($counts, fn ($v) => $v === $minOwn));
        }
    }

    $save = $pdo->prepare(
        'INSERT INTO gameweek_points (gameweek_id, player_id, base_points, is_star, owners_count, final_points)
         VALUES (?, ?, ?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE base_points = VALUES(base_points), is_star = VALUES(is_star), final_points = VALUES(final_points)'
    );

    foreach ($totals as $pid => $base) {
        $isStar = in_array($pid, $stars, true) ? 1 : 0;
        $final = $isStar ? $base * 2 : $base;
        $save->execute([$gameweekId, $pid, $base, $isStar, $final]);
    }
}

echo "=== Live matchday sync ===\n";

$tz = new DateTimeZone('Africa/Tunis');
$dates = [];
// Default: today only (Windows task). Pass "window" for yesterday+today+tomorrow.
$mode = $argv[1] ?? 'today';
$offsets = ($mode === 'window') ? [-1, 0, 1] : [0];
foreach ($offsets as $offset) {
    $dates[] = (new DateTime('now', $tz))->modify($offset . ' day')->format('Y-m-d');
}

$imported = 0;
foreach ($dates as $date) {
    echo "\nDate {$date}\n";
    $data = footballApi('/fixtures', ['date' => $date]);
    $err = footballErrors($data);
    if ($err) {
        echo "  skipped: {$err}\n";
        continue;
    }

    foreach ($data['response'] ?? [] as $row) {
        if ((int) ($row['league']['id'] ?? 0) !== FOOTBALL_LEAGUE_ID) {
            continue;
        }

        $homeApi = (int) $row['teams']['home']['id'];
        $awayApi = (int) $row['teams']['away']['id'];
        if (!isset($clubsByApi[$homeApi], $clubsByApi[$awayApi])) {
            echo "  unknown club in " . $row['teams']['home']['name'] . ' vs ' . $row['teams']['away']['name'] . "\n";
            continue;
        }

        $number = roundNumber($row['league']['round'] ?? '');
        if ($number === null) {
            echo "  could not read round for fixture " . $row['fixture']['id'] . "\n";
            continue;
        }

        $gw = ensureGameweek($pdo, $number, tunisTime($row['fixture']['date']));
        $matchId = upsertMatch($pdo, $gw, $clubsByApi[$homeApi], $clubsByApi[$awayApi], $row);
        $imported++;
        $short = $row['fixture']['status']['short'] ?? 'NS';
        echo "  GW{$number} {$clubsByApi[$homeApi]['short_name']} vs {$clubsByApi[$awayApi]['short_name']} {$short} match_id={$matchId}\n";

        if (in_array($short, ['NS', 'TBD', 'PST', 'CANC'], true)) {
            continue;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM player_stats WHERE match_id = ?');
        $countStmt->execute([$matchId]);
        $already = (int) $countStmt->fetchColumn();
        $isLive = !in_array($short, ['FT', 'AET', 'PEN'], true);
        if (!$isLive && $already > 0) {
            echo "    stats already saved, skip API events\n";
            continue;
        }

        $ev = footballApi('/fixtures/events', ['fixture' => (int) $row['fixture']['id']]);
        if (footballErrors($ev)) {
            echo '    events: ' . footballErrors($ev) . "\n";
            $events = [];
        } else {
            $events = $ev['response'] ?? [];
        }

        $lu = footballApi('/fixtures/lineups', ['fixture' => (int) $row['fixture']['id']]);
        $lineups = footballErrors($lu) ? [] : ($lu['response'] ?? []);
        echo '    events=' . count($events) . ' lineups=' . count($lineups) . "\n";

        $stats = statsFromLineupsAndEvents($lineups, $events);
        savePlayerStats(
            $pdo,
            $matchId,
            $stats,
            $playersByApi,
            $clubsByApi,
            $homeApi,
            $awayApi,
            $row['goals']['home'],
            $row['goals']['away']
        );

        $allFinished = (int) $pdo->query(
            'SELECT COUNT(*) FROM matches WHERE gameweek_id = ' . (int) $gw['id'] . " AND status <> 'finished'"
        )->fetchColumn() === 0;
        $enoughMatches = (int) $pdo->query(
            'SELECT COUNT(*) FROM matches WHERE gameweek_id = ' . (int) $gw['id']
        )->fetchColumn() >= 8;

        refreshGameweekPoints($pdo, (int) $gw['id'], $allFinished && $enoughMatches);
        if ($allFinished && $enoughMatches) {
            $pdo->prepare("UPDATE gameweeks SET status = 'finished' WHERE id = ?")->execute([$gw['id']]);
        } else {
            $pdo->prepare("UPDATE gameweeks SET status = 'open' WHERE id = ?")->execute([$gw['id']]);
        }
    }
}

echo "\nMatches touched: {$imported}\n";
echo "Gameweeks:\n";
foreach ($pdo->query('SELECT number, deadline, status FROM gameweeks ORDER BY number') as $gw) {
    echo "  GW{$gw['number']} {$gw['status']} deadline {$gw['deadline']}\n";
}
echo "Matches:\n";
foreach ($pdo->query(
    'SELECT g.number, c1.short_name AS home, c2.short_name AS away, m.kickoff, m.home_score, m.away_score, m.status
     FROM matches m
     JOIN gameweeks g ON g.id = m.gameweek_id
     JOIN clubs c1 ON c1.id = m.home_club_id
     JOIN clubs c2 ON c2.id = m.away_club_id
     ORDER BY m.kickoff'
) as $m) {
    echo "  GW{$m['number']} {$m['home']} vs {$m['away']} {$m['kickoff']} {$m['status']} {$m['home_score']}-{$m['away_score']}\n";
}

echo "\nTop live/base points this DB:\n";
foreach ($pdo->query(
    'SELECT p.name, c.short_name, gp.base_points, gp.final_points, gp.is_star, g.number
     FROM gameweek_points gp
     JOIN players p ON p.id = gp.player_id
     JOIN clubs c ON c.id = p.club_id
     JOIN gameweeks g ON g.id = gp.gameweek_id
     ORDER BY gp.final_points DESC, p.name
     LIMIT 15'
) as $r) {
    echo "  GW{$r['number']} {$r['name']} ({$r['short_name']}) base={$r['base_points']} final={$r['final_points']} star={$r['is_star']}\n";
}

echo "Done.\n";
