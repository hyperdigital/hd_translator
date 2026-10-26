
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
-- Who asked for which language on which day. One row is one visitor, one language, one day,
-- and nothing else: the row is its own primary key, so InnoDB stores it once instead of a row
-- plus an index pointing at it. "visitor" is a hash keyed with the day, it cannot be traced
-- back to anybody and no address is stored. See AiRequestStatisticsService.
--
CREATE TABLE tx_hdtranslator_ai_request (
	target_language varchar(16) DEFAULT '' NOT NULL,
	day int(11) DEFAULT '0' NOT NULL,
	visitor varchar(16) DEFAULT '' NOT NULL,

	PRIMARY KEY (target_language, day, visitor),
	KEY day (day)
);
