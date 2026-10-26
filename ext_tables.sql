
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

--
-- What a language was asked for per month, kept after the daily rows of that month are
-- pruned. One row per language per month, so a decade of history costs less than a day of
-- detail. "visitor_days" is the sum of the daily unique counts: a person who asked on three
-- days counts three times. It is not the number of distinct people in the month, which
-- cannot be recovered once the daily rows are gone - the hash is re-salted every day on
-- purpose. See AiRequestStatisticsService::rollUpMonth().
--
CREATE TABLE tx_hdtranslator_ai_request_month (
	target_language varchar(16) DEFAULT '' NOT NULL,
	month int(11) DEFAULT '0' NOT NULL,
	visitor_days int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (target_language, month)
);

--
-- Which pages a language was asked for on. Bounded by construction: a language-day accepts
-- at most PAGE_SLOTS distinct pages, and the daily prune keeps only the busiest PAGE_KEEP of
-- the days that have closed. No visitor is involved, so this counts requests, not people.
-- The page comes from the referer the browser sends with the endpoint call.
--
CREATE TABLE tx_hdtranslator_ai_request_page (
	target_language varchar(16) DEFAULT '' NOT NULL,
	day int(11) DEFAULT '0' NOT NULL,
	page varchar(191) DEFAULT '' NOT NULL,
	requests int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (target_language, day, page),
	KEY day (day)
);
