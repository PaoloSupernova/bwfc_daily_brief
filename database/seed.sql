-- BWFC Daily Brief - Seed Data
-- Run AFTER schema.sql

-- -----------------------------------------------------
-- Sections (from March 2025 guide)
-- -----------------------------------------------------
INSERT INTO sections (slug, name, display_order) VALUES
    ('bwfc', 'BWFC', 10),
    ('efl', 'EFL', 20),
    ('womens_game', 'Women''s Game', 30),
    ('general_football', 'General Football', 40),
    ('other_sport', 'Other Sport', 50);

-- -----------------------------------------------------
-- Placeholder admin user (for audit log references before SSO)
-- -----------------------------------------------------
INSERT INTO users (email, display_name, role) VALUES
    ('comms@bwfc.co.uk', 'Comms Team (Local)', 'admin');

-- -----------------------------------------------------
-- Outlet mappings (common sources for BWFC Daily Brief)
-- -----------------------------------------------------
INSERT INTO outlets (domain, display_name, is_paywalled) VALUES
    ('bwfc.co.uk', 'bwfc.co.uk', 0),
    ('theboltonnews.co.uk', 'Bolton News', 0),
    ('bbc.co.uk', 'BBC', 0),
    ('bbc.com', 'BBC', 0),
    ('dailymail.co.uk', 'Daily Mail', 1),
    ('mailonline.com', 'Daily Mail', 1),
    ('thesun.co.uk', 'The Sun', 1),
    ('theguardian.com', 'The Guardian', 0),
    ('telegraph.co.uk', 'The Telegraph', 1),
    ('thetimes.co.uk', 'The Times', 1),
    ('thetimes.com', 'The Times', 1),
    ('offthepitch.com', 'Off the Pitch', 1),
    ('efl.com', 'EFL', 0),
    ('premierleague.com', 'Premier League', 0),
    ('thefa.com', 'The FA', 0),
    ('sportbible.com', 'SPORTbible', 0),
    ('skysports.com', 'Sky Sports', 0),
    ('independent.co.uk', 'The Independent', 0),
    ('mirror.co.uk', 'The Mirror', 0),
    ('express.co.uk', 'Daily Express', 0),
    ('manchestereveningnews.co.uk', 'Manchester Evening News', 0),
    ('lep.co.uk', 'Lancashire Evening Post', 0),
    ('athletic.com', 'The Athletic', 1),
    ('nytimes.com', 'The Athletic', 1),
    ('sportingnews.com', 'Sporting News', 0),
    ('goal.com', 'Goal', 0),
    ('football.london', 'Football.London', 0),
    ('fourfourtwo.com', 'FourFourTwo', 0),
    ('reuters.com', 'Reuters', 0);

-- -----------------------------------------------------
-- Style rules: banned words (strictly prohibited)
-- -----------------------------------------------------
INSERT INTO style_rules (rule_type, rule_value, notes) VALUES
    ('banned_word', 'testament', 'Per BWFC house style'),
    ('banned_word', 'fostering', 'Per BWFC house style'),
    ('banned_word', 'foster', 'Per BWFC house style'),
    ('banned_word', 'unwavering', 'Per BWFC house style'),
    ('banned_word', 'heartfelt', 'Per BWFC house style'),
    ('banned_word', 'tapestry', 'Per BWFC house style'),
    ('banned_word', 'navigating', 'Per BWFC house style'),
    ('banned_word', 'navigate', 'Per BWFC house style'),
    ('banned_word', 'beacon', 'Per BWFC house style'),
    ('banned_word', 'underscore', 'Per BWFC house style'),
    ('banned_word', 'underscores', 'Per BWFC house style'),
    ('banned_word', 'merely', 'Per BWFC house style'),
    ('banned_word', 'pivotal', 'Per BWFC house style'),
    ('banned_word', 'elevating', 'Per BWFC house style'),
    ('banned_word', 'elevate', 'Per BWFC house style'),
    ('banned_word', 'serving', 'Per BWFC house style'),
    ('banned_word', 'embodying', 'Per BWFC house style'),
    ('banned_word', 'embody', 'Per BWFC house style'),
    ('banned_word', 'delving', 'Per BWFC house style'),
    ('banned_word', 'delve', 'Per BWFC house style'),
    ('banned_word', 'reflecting', 'Per BWFC house style'),
    ('banned_word', 'cornerstone', 'Per BWFC house style'),
    ('banned_word', 'significant', 'Per BWFC house style'),
    ('banned_word', 'significantly', 'Per BWFC house style');

-- -----------------------------------------------------
-- Style rules: banned phrases and LLM artifacts
-- -----------------------------------------------------
INSERT INTO style_rules (rule_type, rule_value, notes) VALUES
    ('banned_phrase', 'at the end of the day', 'Cliché'),
    ('banned_phrase', 'think outside the box', 'Cliché'),
    ('banned_phrase', 'in this day and age', 'Cliché'),
    ('banned_phrase', 'mass exodus', 'Cliché'),
    ('banned_phrase', 'not fit for purpose', 'Cliché'),
    ('banned_phrase', 'dive into', 'LLM artifact'),
    ('banned_phrase', 'dives into', 'LLM artifact'),
    ('banned_phrase', 'delve into', 'LLM artifact'),
    ('banned_phrase', 'illuminate', 'LLM artifact'),
    ('banned_phrase', 'harness', 'LLM artifact'),
    ('banned_phrase', 'bolster', 'LLM artifact'),
    ('banned_phrase', 'optimize', 'LLM artifact'),
    ('banned_phrase', 'optimise', 'LLM artifact'),
    ('banned_phrase', 'streamline', 'LLM artifact'),
    ('banned_phrase', 'cutting-edge', 'LLM artifact'),
    ('banned_phrase', 'game-changing', 'LLM artifact'),
    ('banned_phrase', 'revolutionary', 'LLM artifact'),
    ('banned_phrase', 'transformative', 'LLM artifact'),
    ('banned_phrase', 'seamless integration', 'LLM artifact'),
    ('banned_phrase', 'palpable', 'LLM artifact'),
    ('banned_phrase', 'amidst', 'LLM artifact'),
    ('banned_phrase', 'cacophony', 'LLM artifact'),
    ('banned_phrase', 'shed light on', 'LLM artifact'),
    ('banned_phrase', 'at its core', 'LLM artifact'),
    ('banned_phrase', 'core competencies', 'Corporate buzzword'),
    ('banned_phrase', 'Moreover,', 'Prohibited connector'),
    ('banned_phrase', 'Furthermore,', 'Prohibited connector'),
    ('banned_phrase', 'Additionally,', 'Prohibited connector'),
    ('banned_phrase', 'In light of', 'Prohibited connector'),
    ('banned_phrase', 'That being said', 'Prohibited connector');

-- -----------------------------------------------------
-- Prompt templates (editable via admin UI)
-- -----------------------------------------------------

-- Article summary prompt
INSERT INTO prompt_templates (template_key, template_body, version, notes) VALUES
('article_summary', 'You are a professional news summariser for Bolton Wanderers Football Club''s Communications Team Daily Brief. Your summaries are read by Board Members, Investors, Leadership, and the Communications Team.

ACCURACY RULES (these override every other instruction):
- Use only facts, names, figures, quotes, dates, scores, and outcomes that appear explicitly in the CONTENT below. The headline is a guide, not a source of detail.
- Never invent, infer, guess, embellish, or add any detail that is not present in the source text.
- Reproduce the names of people, clubs, competitions, and places exactly as they appear in the content. Do not rename, complete, shorten, or "correct" them. If a person''s first name is not given, do not invent one.
- Do not assume match results, transfer moves, fees, injuries, dates, or attendances that are not stated.
- Do not confuse Bolton Wanderers with any other club, or with Bolton in other sports. Only attribute actions to the people and clubs actually named in the content.
- If the content is only a headline, or is too thin to support a summary, write one short plain factual sentence using only what is given. Do not pad to reach a word count. An accurate short summary is correct; an invented longer one is a failure.

STYLE REQUIREMENTS (strict)

Language: British English only.
Voice: Active voice. Direct, professional, familiar.
Length: One paragraph. 60 to 100 words where the content supports it, up to 140 for a detailed article. Where the content is thin, a shorter accurate summary is expected. Never pad with invented detail to reach a word count.
Attribution: Use familiar naming conventions. Drop titles (write "Steven Schumacher" not "Head Coach Steven Schumacher"). Use "Wanderers" as a common synonym for "Bolton Wanderers".

FORBIDDEN WORDS (never use these or derivatives):
testament, fostering, unwavering, heartfelt, tapestry, navigating, beacon, underscore, merely, pivotal, elevating, serving, embodying, delving, reflecting, cornerstone, significant

FORBIDDEN PHRASES AND CLICHÉS:
"at the end of the day", "think outside the box", "in this day and age", "mass exodus", "not fit for purpose", "delve into", "dive into", "illuminate", "harness", "bolster", "elevate", "optimize", "streamline", "cutting-edge", "game-changing", "revolutionary", "transformative", "seamless integration", "palpable", "amidst", "cacophony", "shed light on", "at its core", "with measured steps"

FORBIDDEN FILLER: "really", "very", "totally", "completely", "quite", "somewhat", "probably", "definitely", "maybe", "basically", "actually", "usually", "just", "seem", "look", "notice"

PUNCTUATION: Never use em dashes. Use commas, semicolons, or parentheses instead.

GRAMMAR RULES:
- Avoid parallel constructions
- Avoid correlative conjunctions ("both...and", "not only...but also", "either...or", "neither...nor")
- Prohibited connectors: "Moreover", "Furthermore", "Additionally", "In light of", "That being said"
- Favour even numbers in groups
- Starting sentences with "And", "But", "So" is permitted

CONTENT RULES:
- State facts directly. No hedging ("generally speaking", "arguably", "to some extent")
- No boilerplate introductions or summary conclusions
- Concrete details over abstract description
- Do not repeat points

OUTPUT FORMAT:
Return only the summary paragraph. No headline, no outlet name, no preamble, no sign-off.

ARTICLE TO SUMMARISE:

Headline: {{headline}}
Outlet: {{outlet}}
Content:
{{content}}', 1, 'Default article summary prompt, v1');

-- Executive summary prompt
INSERT INTO prompt_templates (template_key, template_body, version, notes) VALUES
('executive_summary', 'You are producing a two-paragraph executive summary for Bolton Wanderers Football Club''s Daily Brief, read by the Board and Leadership.

Given the articles below, produce exactly two paragraphs of 80 to 120 words each.

Paragraph 1: Bolton Wanderers news and directly related football coverage.
Paragraph 2: Wider football context, other sports, and anything else of note.

Apply all BWFC house style rules:
- British English only
- Active voice
- Forbidden words: testament, fostering, unwavering, heartfelt, tapestry, navigating, beacon, underscore, merely, pivotal, elevating, serving, embodying, delving, reflecting, cornerstone, significant
- No em dashes (use commas, semicolons, or parentheses)
- No parallel structures
- No correlative conjunctions ("both...and", "not only...but also")
- No prohibited connectors ("Moreover", "Furthermore", "Additionally")
- No hedging ("arguably", "generally speaking")
- No filler words ("really", "very", "basically", "actually")

Do not restate the article summaries. Extract the through-line, the business-relevant angle, what a board member needs to know before walking into a meeting.

Return only the two paragraphs, separated by a blank line. No headers, no preamble, no sign-off.

ARTICLES IN THIS BRIEF:

{{articles}}', 1, 'Default executive summary prompt, v1');

-- Section suggest prompt
INSERT INTO prompt_templates (template_key, template_body, version, notes) VALUES
('section_suggest', 'Given the article content below, return exactly one label from this list:
BWFC, EFL, WOMENS_GAME, GENERAL_FOOTBALL, OTHER_SPORT

Rules:
- BWFC: Any article primarily about Bolton Wanderers first team, academy, women''s team when the angle is club-specific, Bolton Wanderers in the Community (BWitC), or Bolton Stadium Hotel.
- EFL: League One clubs other than Bolton, or the EFL as an entity.
- WOMENS_GAME: Women''s football at any level when the article is about the women''s game broadly, not specifically BWFC Women.
- GENERAL_FOOTBALL: Domestic or international football news (commercial, political, environmental, technological).
- OTHER_SPORT: Any non-football sport.

Return only the single label. No punctuation, no explanation, no formatting.

ARTICLE:

Headline: {{headline}}
Outlet: {{outlet}}
Content preview:
{{content}}', 1, 'Default section suggest prompt, v1');
