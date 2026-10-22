-- Ein Dating-Profil pro Account (nicht pro Provider: Steuer-ID/Rechtsform
-- sind hier irrelevant). Alles, was der Kern nicht hat - Alter, Geschlecht,
-- Vorliebe, Standort fuer die Umkreissuche - liegt komplett in dieser
-- Tabelle. lat/lng werden erst beim Speichern von Ort/PLZ ueber den
-- Geocoder ermittelt und sind bis dahin NULL (keine Umkreissuche moeglich).
CREATE TABLE x_dating_profile (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    birthdate DATE NOT NULL,
    gender ENUM('female', 'male', 'other') NOT NULL,
    seeking ENUM('female', 'male', 'other', 'any') NOT NULL DEFAULT 'any',
    bio TEXT NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(100) NULL,
    country CHAR(2) NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    geocoded_at DATETIME NULL,
    max_distance_km INT UNSIGNED NOT NULL DEFAULT 50,
    status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_dating_profile_account (account_id),
    KEY idx_x_dating_profile_status (status, gender, seeking),
    CONSTRAINT fk_x_dating_profile_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fotogalerie, Vorbild x_card_gallery. Pfad statt Groesse/Name getrennt zu
-- fuehren, da es hier keine zwei Groessenvarianten wie bei offer_image gibt.
CREATE TABLE x_dating_photo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id INT UNSIGNED NOT NULL,
    name CHAR(32) NOT NULL,
    extension VARCHAR(5) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_x_dating_photo_profile (profile_id, position),
    CONSTRAINT fk_x_dating_photo_profile FOREIGN KEY (profile_id) REFERENCES x_dating_profile (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eine Zeile pro Swipe-Aktion (Like oder Pass). Grundlage fuer die
-- Swipe-Queue (ausschliessen, was schon entschieden wurde) und fuer die
-- Match-Erkennung: ein Match entsteht, sobald fuer ein Paar in beiden
-- Richtungen eine 'like'-Zeile existiert.
CREATE TABLE x_dating_swipe (
    liker_account_id INT UNSIGNED NOT NULL,
    target_account_id INT UNSIGNED NOT NULL,
    direction ENUM('like', 'pass') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (liker_account_id, target_account_id),
    KEY idx_x_dating_swipe_target (target_account_id, direction),
    KEY idx_x_dating_swipe_liker_created (liker_account_id, created_at),
    CONSTRAINT fk_x_dating_swipe_liker FOREIGN KEY (liker_account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_swipe_target FOREIGN KEY (target_account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- account_a_id ist immer die kleinere der beiden Account-IDs, account_b_id
-- die groessere - so bleibt das Paar pro Match eindeutig (UNIQUE), egal wer
-- zuerst geliked hat.
CREATE TABLE x_dating_match (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_a_id INT UNSIGNED NOT NULL,
    account_b_id INT UNSIGNED NOT NULL,
    status ENUM('active', 'unmatched') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    unmatched_at DATETIME NULL,
    UNIQUE KEY uq_x_dating_match_pair (account_a_id, account_b_id),
    KEY idx_x_dating_match_b (account_b_id),
    CONSTRAINT fk_x_dating_match_a FOREIGN KEY (account_a_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_match_b FOREIGN KEY (account_b_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chat innerhalb eines Matches. Lesestatus laeuft ueber den Kern-Mechanismus
-- message_seen mit scope='dating_match', ref_id=match_id, sub_id=0 (1:1-
-- Konversation, kein Mehrfach-Besucher-Fall wie bei offer_message).
CREATE TABLE x_dating_message (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    author_account_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_x_dating_message_match (match_id, id),
    CONSTRAINT fk_x_dating_message_match FOREIGN KEY (match_id) REFERENCES x_dating_match (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_message_author FOREIGN KEY (author_account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Peer-zu-Peer-Block, wirkt in beiden Richtungen (Swipe-Queue-Ausschluss,
-- Chat-Sperre). Bewusst getrennt von der admin-seitigen Kontosperre
-- (account.status) - das hier ist eine Entscheidung einzelner Nutzer
-- gegeneinander, keine Moderationsmassnahme.
CREATE TABLE x_dating_block (
    blocker_account_id INT UNSIGNED NOT NULL,
    blocked_account_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (blocker_account_id, blocked_account_id),
    KEY idx_x_dating_block_blocked (blocked_account_id),
    CONSTRAINT fk_x_dating_block_blocker FOREIGN KEY (blocker_account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_block_blocked FOREIGN KEY (blocked_account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eigenes Melde-System statt der Kern-Tabelle "report": die ist fuer
-- DMCA-artige Inhalts-Meldungen an den Betreiber geformt (Kategorien wie
-- intellectual_property), nicht fuer "dieser Nutzer hat sich schlecht
-- verhalten". match_id ist optional, da auch ohne aktives Match gemeldet
-- werden kann.
CREATE TABLE x_dating_report (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_account_id INT UNSIGNED NOT NULL,
    reported_account_id INT UNSIGNED NOT NULL,
    match_id INT UNSIGNED NULL,
    category ENUM('harassment', 'fake_profile', 'inappropriate_content', 'other') NOT NULL,
    explanation TEXT NULL,
    status ENUM('open', 'actioned', 'rejected') NOT NULL DEFAULT 'open',
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_x_dating_report_status (status, created_at),
    KEY idx_x_dating_report_reported (reported_account_id),
    CONSTRAINT fk_x_dating_report_reporter FOREIGN KEY (reporter_account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_report_reported FOREIGN KEY (reported_account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_dating_report_match FOREIGN KEY (match_id) REFERENCES x_dating_match (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
