
CREATE TABLE sys_reaction (
	hdtranslator_language int(11) DEFAULT '0' NOT NULL,
	hdtranslator_site varchar(255) DEFAULT '' NOT NULL,
	hdtranslator_qa_mode varchar(20) DEFAULT 'report' NOT NULL
);

--
-- Lookup of the AI translation cache: target language plus source text.
-- Without it every cached string of a frontend batch is a full table scan.
--
CREATE TABLE tx_hdtranslator_ai_translation
(
	KEY lang_source (target_language(16), original_source(191))
);

--
-- How many people ask for a translation, per language and day.
-- "visitor" is a hash keyed with the day, it cannot be traced back to anybody and no address
-- is stored. See AiRequestStatisticsService.
--
CREATE TABLE tx_hdtranslator_ai_request (
	target_language varchar(16) DEFAULT '' NOT NULL,
	day int(11) DEFAULT '0' NOT NULL,
	visitor varchar(64) DEFAULT '' NOT NULL,
	requests int(11) DEFAULT '0' NOT NULL,
	strings int(11) DEFAULT '0' NOT NULL,
	crdate int(11) DEFAULT '0' NOT NULL,

	UNIQUE KEY visit (target_language, day, visitor),
	KEY day (day)
);
