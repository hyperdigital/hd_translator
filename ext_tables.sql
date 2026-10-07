--
-- Lookup of the AI translation cache: target language plus source text.
-- Without it every cached string of a frontend batch is a full table scan.
--
CREATE TABLE tx_hdtranslator_ai_translation
(
	KEY lang_source (target_language(16), original_source(191))
);
