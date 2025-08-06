CREATE TABLE `tx_hdtranslator_ai_translation` (
	target_language varchar(20) DEFAULT '' NOT NULL,
	original_source TEXT,
	original_translation TEXT,
	`translation` TEXT,
);
CREATE TABLE `tx_hdtranslator_ai_languages`
(
	language varchar(20) DEFAULT '' NOT NULL,
	name varchar(255) DEFAULT '' NOT NULL,
);
