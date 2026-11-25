-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Isäntä: db
-- Luontiaika: 03.11.2026 klo 12:32
-- Palvelimen versio: 8.0.46
-- PHP-versio 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Tietokanta: `Luokka_Tietokanta`
--

-- --------------------------------------------------------

--
-- Rakenne taululle `kayttaja`
--

CREATE TABLE `kayttaja` (
  `Kayttaja_id` int NOT NULL,
  `Nimi` varchar(50) NOT NULL,
  `Salasana` varchar(255) NOT NULL,
  `Sahkoposti` varchar(100) NOT NULL,
  `Rooli` varchar(50) NOT NULL,
  `PuhelinNum` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Vedos taulusta `kayttaja`
--

INSERT INTO `kayttaja` (`Kayttaja_id`, `Nimi`, `Salasana`, `Sahkoposti`, `Rooli`, `PuhelinNum`) VALUES
(1, 'Matti Meikalainen', 'testi123', 'matti@gmail.com', 'Opettaja', '0401234567'),
(2, 'Liisa Virtanen', 'testi123', 'liisa@gmail.com', 'Opettaja', '0402345678'),
(3, 'Mikko Korhonen', 'testi123', 'mikko@gmail.com', 'Opiskelija', '0403456789'),
(4, 'Anna Nieminen', 'testi123', 'anna@gmail.com', 'Opiskelija', '0404567890'),
(5, 'Pekka Laine', 'testi123', 'pekka@gmail.com', 'Opiskelija', '0405678901'),
(6, 'Sari Heikkinen', 'testi123', 'sari@gmail.com', 'Yllapitaja', '0406789012'),
(7, 'Janne Virtanen', 'testi123', 'janne@gmail.com', 'Opiskelija', '0407890123'),
(8, 'Emma Salonen', 'testi123', 'emma@gmail.com', 'Opiskelija', '0408901234');

-- --------------------------------------------------------

--
-- Rakenne taululle `Luokka`
--

CREATE TABLE `Luokka` (
  `Luokka_id` int NOT NULL,
  `Nimi` varchar(50) NOT NULL,
  `Kurssinimi` varchar(50) NOT NULL,
  `Oppilaitos` varchar(50) NOT NULL,
  `sijainti` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Vedos taulusta `Luokka`
--

INSERT INTO `Luokka` (`Luokka_id`, `Nimi`, `Kurssinimi`, `Oppilaitos`, `sijainti`) VALUES
(1, 'Luokka 101', 'Ohjelmointi', 'Omnia', 'Espoo'),
(2, 'Luokka 102', 'Tietokannat', 'Omnia', 'Espoo'),
(3, 'Luokka 201', 'Web-kehitys', 'Metropolia', 'Helsinki'),
(4, 'Luokka 202', 'Tietoverkot', 'Metropolia', 'Vantaa'),
(5, 'Luokka 301', 'Matematiikka', 'Stadia', 'Helsinki'),
(6, 'Luokka 302', 'Ohjelmistotuotanto', 'Omnia', 'Espoo');

-- --------------------------------------------------------

--
-- Rakenne taululle `Varaus`
--

CREATE TABLE `Varaus` (
  `Varaus_id` int NOT NULL,
  `Luokka_id` int NOT NULL,
  `Kayttaja_id` int NOT NULL,
  `PVM_Varattu` date NOT NULL,
  `Aika_Aloitus` time NOT NULL,
  `Aika_loppu` time NOT NULL,
  `Tila` varchar(50) NOT NULL,
  `Jasenet` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Vedos taulusta `Varaus`
--

INSERT INTO `Varaus` (`Varaus_id`, `Luokka_id`, `Kayttaja_id`, `PVM_Varattu`, `Aika_Aloitus`, `Aika_loppu`, `Tila`, `Jasenet`) VALUES
(1, 1, 1, '2026-11-04', '08:00:00', '09:00:00', 'Varattu', 'Matti, Mikko'),
(2, 2, 2, '2026-11-04', '10:00:00', '12:00:00', 'Varattu', 'Liisa, Anna'),
(3, 3, 3, '2026-11-05', '09:00:00', '10:30:00', 'Varattu', 'Mikko, Pekka'),
(4, 4, 4, '2026-11-05', '12:00:00', '13:00:00', 'Vapaa', ''),
(5, 5, 5, '2026-11-06', '13:00:00', '15:00:00', 'Varattu', 'Pekka, Janne'),
(6, 1, 6, '2026-11-06', '14:00:00', '16:00:00', 'Varattu', 'Sari, Emma'),
(7, 2, 7, '2026-11-07', '08:00:00', '09:30:00', 'Peruttu', ''),
(8, 3, 8, '2026-11-07', '11:00:00', '12:00:00', 'Varattu', 'Emma, Anna'),
(9, 4, 1, '2026-11-08', '15:00:00', '16:30:00', 'Varattu', 'Matti, Liisa'),
(10, 5, 2, '2026-11-09', '09:00:00', '11:00:00', 'Vapaa', ''),
(11, 6, 3, '2026-11-10', '08:30:00', '10:00:00', 'Varattu', 'Mikko, Anna'),
(12, 1, 4, '2026-11-10', '12:00:00', '14:00:00', 'Varattu', 'Anna, Pekka'),
(13, 2, 5, '2026-11-11', '10:00:00', '11:30:00', 'Peruttu', ''),
(14, 3, 6, '2026-11-12', '13:00:00', '15:00:00', 'Varattu', 'Sari, Janne'),
(15, 6, 7, '2026-11-13', '14:00:00', '16:00:00', 'Varattu', 'Janne, Emma');

--
-- Indexes for dumped tables
--

--
-- Indeksit taulukolle `kayttaja`
--
ALTER TABLE `kayttaja`
  ADD PRIMARY KEY (`Kayttaja_id`);

--
-- Indeksit taulukolle `Luokka`
--
ALTER TABLE `Luokka`
  ADD PRIMARY KEY (`Luokka_id`);

--
-- Indeksit taulukolle `Varaus`
--
ALTER TABLE `Varaus`
  ADD PRIMARY KEY (`Varaus_id`),
  ADD KEY `Luokka_id` (`Luokka_id`),
  ADD KEY `Kayttaja_id` (`Kayttaja_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `kayttaja`
--
ALTER TABLE `kayttaja`
  MODIFY `Kayttaja_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `Luokka`
--
ALTER TABLE `Luokka`
  MODIFY `Luokka_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `Varaus`
--
ALTER TABLE `Varaus`
  MODIFY `Varaus_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Rajoitteet vedostauluille
--

--
-- Rajoitteet taululle `Varaus`
--
ALTER TABLE `Varaus`
  ADD CONSTRAINT `Varaus_ibfk_1` FOREIGN KEY (`Luokka_id`) REFERENCES `Luokka` (`Luokka_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `Varaus_ibfk_2` FOREIGN KEY (`Kayttaja_id`) REFERENCES `kayttaja` (`Kayttaja_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
