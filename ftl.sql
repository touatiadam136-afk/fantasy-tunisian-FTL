-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : ven. 06 nov. 2026 à 21:53
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `ftl`
--

-- --------------------------------------------------------

--
-- Structure de la table `clubs`
--

CREATE TABLE `clubs` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `short_name` varchar(10) NOT NULL,
  `api_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `gameweeks`
--

CREATE TABLE `gameweeks` (
  `id` int(10) UNSIGNED NOT NULL,
  `number` int(10) UNSIGNED NOT NULL,
  `deadline` datetime NOT NULL,
  `status` enum('upcoming','open','finished') NOT NULL DEFAULT 'upcoming'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `gameweek_points`
--

CREATE TABLE `gameweek_points` (
  `id` int(10) UNSIGNED NOT NULL,
  `gameweek_id` int(10) UNSIGNED NOT NULL,
  `player_id` int(10) UNSIGNED NOT NULL,
  `base_points` int(11) NOT NULL DEFAULT 0,
  `is_star` tinyint(1) NOT NULL DEFAULT 0,
  `owners_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `final_points` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `leagues`
--

CREATE TABLE `leagues` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `code` varchar(10) NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `league_members`
--

CREATE TABLE `league_members` (
  `league_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `matches`
--

CREATE TABLE `matches` (
  `id` int(10) UNSIGNED NOT NULL,
  `gameweek_id` int(10) UNSIGNED NOT NULL,
  `home_club_id` int(10) UNSIGNED NOT NULL,
  `away_club_id` int(10) UNSIGNED NOT NULL,
  `kickoff` datetime NOT NULL,
  `home_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `away_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `status` enum('scheduled','finished') NOT NULL DEFAULT 'scheduled',
  `api_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `players`
--

CREATE TABLE `players` (
  `id` int(10) UNSIGNED NOT NULL,
  `club_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` enum('GK','DEF','MID','FWD') NOT NULL,
  `price` decimal(4,1) NOT NULL,
  `api_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `player_stats`
--

CREATE TABLE `player_stats` (
  `id` int(10) UNSIGNED NOT NULL,
  `match_id` int(10) UNSIGNED NOT NULL,
  `player_id` int(10) UNSIGNED NOT NULL,
  `minutes` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `goals` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `assists` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `yellow_cards` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `red_cards` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `own_goals` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `missed_penalties` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `clean_sheet` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `squads`
--

CREATE TABLE `squads` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `gameweek_id` int(10) UNSIGNED NOT NULL,
  `captain_player_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `squad_players`
--

CREATE TABLE `squad_players` (
  `id` int(10) UNSIGNED NOT NULL,
  `squad_id` int(10) UNSIGNED NOT NULL,
  `player_id` int(10) UNSIGNED NOT NULL,
  `is_starter` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `team_name` varchar(60) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_id` (`api_id`);

--
-- Index pour la table `gameweeks`
--
ALTER TABLE `gameweeks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `number` (`number`);

--
-- Index pour la table `gameweek_points`
--
ALTER TABLE `gameweek_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gw_player` (`gameweek_id`,`player_id`),
  ADD KEY `player_id` (`player_id`);

--
-- Index pour la table `leagues`
--
ALTER TABLE `leagues`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Index pour la table `league_members`
--
ALTER TABLE `league_members`
  ADD PRIMARY KEY (`league_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Index pour la table `matches`
--
ALTER TABLE `matches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_id` (`api_id`),
  ADD KEY `gameweek_id` (`gameweek_id`),
  ADD KEY `home_club_id` (`home_club_id`),
  ADD KEY `away_club_id` (`away_club_id`);

--
-- Index pour la table `players`
--
ALTER TABLE `players`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_id` (`api_id`),
  ADD KEY `club_id` (`club_id`);

--
-- Index pour la table `player_stats`
--
ALTER TABLE `player_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_match_player` (`match_id`,`player_id`),
  ADD KEY `player_id` (`player_id`);

--
-- Index pour la table `squads`
--
ALTER TABLE `squads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_gameweek` (`user_id`,`gameweek_id`),
  ADD KEY `gameweek_id` (`gameweek_id`),
  ADD KEY `captain_player_id` (`captain_player_id`);

--
-- Index pour la table `squad_players`
--
ALTER TABLE `squad_players`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_squad_player` (`squad_id`,`player_id`),
  ADD KEY `player_id` (`player_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `clubs`
--
ALTER TABLE `clubs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `gameweeks`
--
ALTER TABLE `gameweeks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `gameweek_points`
--
ALTER TABLE `gameweek_points`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `leagues`
--
ALTER TABLE `leagues`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `matches`
--
ALTER TABLE `matches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `players`
--
ALTER TABLE `players`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `player_stats`
--
ALTER TABLE `player_stats`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `squads`
--
ALTER TABLE `squads`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `squad_players`
--
ALTER TABLE `squad_players`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `gameweek_points`
--
ALTER TABLE `gameweek_points`
  ADD CONSTRAINT `gameweek_points_ibfk_1` FOREIGN KEY (`gameweek_id`) REFERENCES `gameweeks` (`id`),
  ADD CONSTRAINT `gameweek_points_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`);

--
-- Contraintes pour la table `leagues`
--
ALTER TABLE `leagues`
  ADD CONSTRAINT `leagues_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Contraintes pour la table `league_members`
--
ALTER TABLE `league_members`
  ADD CONSTRAINT `league_members_ibfk_1` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `league_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `matches`
--
ALTER TABLE `matches`
  ADD CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`gameweek_id`) REFERENCES `gameweeks` (`id`),
  ADD CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`home_club_id`) REFERENCES `clubs` (`id`),
  ADD CONSTRAINT `matches_ibfk_3` FOREIGN KEY (`away_club_id`) REFERENCES `clubs` (`id`);

--
-- Contraintes pour la table `players`
--
ALTER TABLE `players`
  ADD CONSTRAINT `players_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`);

--
-- Contraintes pour la table `player_stats`
--
ALTER TABLE `player_stats`
  ADD CONSTRAINT `player_stats_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`),
  ADD CONSTRAINT `player_stats_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`);

--
-- Contraintes pour la table `squads`
--
ALTER TABLE `squads`
  ADD CONSTRAINT `squads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `squads_ibfk_2` FOREIGN KEY (`gameweek_id`) REFERENCES `gameweeks` (`id`),
  ADD CONSTRAINT `squads_ibfk_3` FOREIGN KEY (`captain_player_id`) REFERENCES `players` (`id`);

--
-- Contraintes pour la table `squad_players`
--
ALTER TABLE `squad_players`
  ADD CONSTRAINT `squad_players_ibfk_1` FOREIGN KEY (`squad_id`) REFERENCES `squads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `squad_players_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
