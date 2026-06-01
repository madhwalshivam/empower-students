<?php
/**
 * includes/isaa_schema.php
 *
 * Schema for the ISAA (Indian Scale for Assessment of Autism) tool.
 *
 * IMPORTANT: This file does NOT create a new partners table — the existing
 * `partners` table from partner_schema.php is reused. We only ADD columns to
 * support partner login (password_hash, magic-link tokens) and ISAA capability.
 *
 * Safe to require_once on every request — all CREATE / ALTER / INSERT statements
 * are idempotent.
 */

// Ensure the existing partners table exists before we ALTER it
if (file_exists(__DIR__ . '/partner_schema.php')) {
    require_once __DIR__ . '/partner_schema.php';
}

(function () {

    // ─────────────────────────────────────────────────────────────
    // Extend existing `partners` table with login + ISAA capability
    // ─────────────────────────────────────────────────────────────
    try {
        $cols = db()->query("PRAGMA table_info(partners)")->fetchAll();
        $names = array_column($cols, 'name');
        foreach ([
            'password_hash'             => 'TEXT',
            'password_setup_token'      => 'TEXT',
            'password_setup_token_at'   => 'TEXT',
            'can_administer_isaa'       => 'INTEGER DEFAULT 0',
            'last_login_at'             => 'TEXT',
            'qualification'             => 'TEXT',
            'institution'               => 'TEXT',
        ] as $col => $type) {
            if (!in_array($col, $names, true)) {
                db()->exec("ALTER TABLE partners ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Throwable $e) {
        error_log('[isaa schema partners ALTER] ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────
    // children.registered_by_partner_id (idempotent ALTER)
    // ─────────────────────────────────────────────────────────────
    try {
        $cols = db()->query("PRAGMA table_info(children)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('registered_by_partner_id', $names, true)) {
            db()->exec("ALTER TABLE children ADD COLUMN registered_by_partner_id INTEGER");
        }
    } catch (Throwable $e) { /* table missing */ }

    // ─────────────────────────────────────────────────────────────
    // ISAA item bank — 40 items across 6 domains, seeded once
    // ─────────────────────────────────────────────────────────────
    db()->exec("CREATE TABLE IF NOT EXISTS isaa_questions (
        item_no            INTEGER PRIMARY KEY,         -- 1..40
        domain_no          INTEGER NOT NULL,            -- 1..6
        domain_label       TEXT NOT NULL,
        item_label         TEXT NOT NULL,
        description        TEXT NOT NULL,
        testing_guidance   TEXT,
        testing_guidance_hi TEXT,
        rating_profile     TEXT DEFAULT 'frequency'      -- frequency | severity | presence
    )");

    // Idempotent ALTER: add testing_guidance_hi if missing (for already-deployed installs)
    try {
        $cols = db()->query("PRAGMA table_info(isaa_questions)")->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('testing_guidance_hi', $names, true)) {
            db()->exec("ALTER TABLE isaa_questions ADD COLUMN testing_guidance_hi TEXT");
        }
        if (!in_array('rating_profile', $names, true)) {
            db()->exec("ALTER TABLE isaa_questions ADD COLUMN rating_profile TEXT DEFAULT 'frequency'");
        }
    } catch (Throwable $e) { /* ignore */ }

    db()->exec("CREATE TABLE IF NOT EXISTS isaa_assessments (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id           INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
        parent_id          INTEGER NOT NULL,
        partner_id         INTEGER REFERENCES partners(id) ON DELETE SET NULL,
        status             TEXT NOT NULL DEFAULT 'paid',  -- paid | in_progress | submitted | cancelled
        paid_at            TEXT,
        started_at         TEXT,
        submitted_at       TEXT,
        total_score        INTEGER,
        category           TEXT,                         -- normal | mild | moderate | severe
        disability_pct     INTEGER,
        domain_scores_json TEXT,
        advice_md          TEXT,
        summary_md         TEXT,
        summary_md_hi      TEXT,
        advice_md_hi       TEXT,
        share_token        TEXT,
        share_pin          TEXT,
        notes              TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_isaa_child ON isaa_assessments(child_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_isaa_partner ON isaa_assessments(partner_id, status)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_isaa_parent ON isaa_assessments(parent_id)");

    // Idempotent ALTER: add new columns to existing table (in case it was already created without them)
    try {
        $cols = db()->query("PRAGMA table_info(isaa_assessments)")->fetchAll();
        $names = array_column($cols, 'name');
        foreach ([
            'summary_md_hi' => 'TEXT',
            'advice_md_hi'  => 'TEXT',
            'share_token'   => 'TEXT',
            'share_pin'     => 'TEXT',
        ] as $col => $type) {
            if (!in_array($col, $names, true)) {
                db()->exec("ALTER TABLE isaa_assessments ADD COLUMN {$col} {$type}");
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Index on share_token MUST come after the ALTER (column may have just been added)
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_isaa_share ON isaa_assessments(share_token)"); } catch (Throwable $e) {}

    db()->exec("CREATE TABLE IF NOT EXISTS isaa_responses (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        assessment_id   INTEGER NOT NULL REFERENCES isaa_assessments(id) ON DELETE CASCADE,
        item_no         INTEGER NOT NULL,
        score           INTEGER NOT NULL,             -- 1..5
        notes           TEXT,
        UNIQUE (assessment_id, item_no)
    )");

    // ─────────────────────────────────────────────────────────────
    // Seed the 40 ISAA items (idempotent — INSERT OR IGNORE on PK)
    // Source: ISAA Test Manual, NIMH (National Institute for the Mentally
    // Handicapped), Government of India.
    // ─────────────────────────────────────────────────────────────
    $items = [
        // ── Domain I: Social Relationship and Reciprocity (items 1-9) ──
        [1, 1, 'Social Relationship and Reciprocity', 'Has poor eye contact',
            'Avoids looking people in the eye. Unable to maintain eye contact as expected for a given age or required of social norms. Eye contact may be unusual such as gazing for too long on one spot or looking sideways.',
            'Observe the child during interaction. Note frequency, duration, and appropriateness of eye contact. Supplement with informant interview.'],
        [2, 1, 'Social Relationship and Reciprocity', 'Lacks social smile',
            'Does not smile when meeting people or in reciprocation. A smile that reflects social response and recognition cannot be elicited.',
            'When the child enters, see how he/she reacts to strangers. Whether smiles or not. How he responds to friendly overtures such as a smile or a handshake.'],
        [3, 1, 'Social Relationship and Reciprocity', 'Remains aloof',
            'Self-absorbed, withdrawn, not responsive to people or environment. Seems preoccupied and away from the social world. Hardly responds to or initiates contact. Lacks age-appropriate pretend play.',
            'Ask the child if he has friends, whom he likes at home or what he likes to eat etc. Observe how responsive the child is when you interact.'],
        [4, 1, 'Social Relationship and Reciprocity', 'Does not reach out to others',
            'Does not interact and remains socially unresponsive. Does not initiate, seek, or respond to social interactions. May not respond to their name, or response may be inappropriate.',
            'Check if the child takes any initiative to elicit a response from others. Does he respond to his name? How does he react when you try to engage him in social interaction?'],
        [5, 1, 'Social Relationship and Reciprocity', 'Unable to relate to people',
            'Does not initiate contact and does not relate to people as expected. Reminders are required to attune to the presence of people and social situations. Persistent effort needed to get attention. Indifferent and impersonal in interactions.',
            'Observe quality of social engagement. Note if the child seems indifferent or distant when contact is established.'],
        [6, 1, 'Social Relationship and Reciprocity', 'Unable to respond to social/environmental cues',
            'Not responsive to social and environmental demands or expectations. Behaviour is not synchronous with the demands of the social environment.',
            'Ask if the child behaves appropriately in expected social situations — e.g. when visiting friends or relatives, in markets etc.'],
        [7, 1, 'Social Relationship and Reciprocity', 'Engages in solitary and repetitive play activities',
            'Plays alone most of the time or prefers solitary activities. Avoids playing with others. May not engage in group oriented activities or tasks at all.',
            'Ask if the child plays in a group with other children or alone with some object/material repetitively.'],
        [8, 1, 'Social Relationship and Reciprocity', 'Unable to take turns in social interaction',
            'Does not comprehend the significance of taking turns in reciprocal interaction. Does not wait until their turn comes.',
            'Check if the child can play with a ball by taking turns. Does he wait for his turn when talking to others?'],
        [9, 1, 'Social Relationship and Reciprocity', 'Does not maintain peer relationships',
            'Does not develop age appropriate friendships. Does not engage in age appropriate peer interactions. Difficulty understanding social rules and conforming to social boundaries.',
            'Ask if the child plays with children of his age, what he plays with them, and how well he mixes/bonds with them.'],

        // ── Domain II: Emotional Responsiveness (items 10-14) ──
        [10, 2, 'Emotional Responsiveness', 'Shows inappropriate emotional response',
            'Does not show the expected feeling in a social situation. Inappropriate emotional responses (laughing when scolded). Inappropriate degree of response (excessive crying or laughing). Unpredictable shifts in emotion without apparent reason.',
            'Observe child during interactions. Ask informants about emotional reactions to common situations.'],
        [11, 2, 'Emotional Responsiveness', 'Shows exaggerated emotions',
            'Anxiety or fear excessive in nature, triggered without apparent reason. May show extreme fear of innocuous objects/events leading to uncontrolled behaviour.',
            'Note any exaggerated reactions during testing. Ask informants for examples.'],
        [12, 2, 'Emotional Responsiveness', 'Engages in self-stimulating emotions',
            'Self-talk inappropriate for age. Smiles to self without apparent reason.',
            'Check if the child talks to self or laughs/smiles/whines for no apparent reason.'],
        [13, 2, 'Emotional Responsiveness', 'Lacks fear of danger',
            'Does not show fear of hazards or dangers which others of the same age would show or know.',
            'Ask informants about awareness of dangers — heights, traffic, hot objects, strangers etc.'],
        [14, 2, 'Emotional Responsiveness', 'Excited or agitated for no apparent reason',
            'Excessive and unwarranted excitement, over-activity or agitation. Moves with brisk energy and may be difficult to control.',
            'Observe activity levels during the session. Ask informants about typical activity at home.'],

        // ── Domain III: Speech-Language and Communication (items 15-23) ──
        [15, 3, 'Speech-Language and Communication', 'Acquired speech and lost it',
            'Speech development is not age-appropriate. May have developed speech but lost it subsequently. About 50% of autistic individuals may be mute.',
            'Ask informants about speech development history — milestones reached, then regression?'],
        [16, 3, 'Speech-Language and Communication', 'Has difficulty in using non-verbal language or gestures to communicate',
            'Difficulty expressing needs non-verbally and understanding non-verbal language of others. Instead of pointing, may drag or pull others\' hand to desired object.',
            'Arrange Cup, Doll, Car, Spoon, Key in a row. Ask the child to point to one of the objects. Note how the child indicates needs.'],
        [17, 3, 'Speech-Language and Communication', 'Engages in stereotyped and repetitive use of language',
            'Repeats a word, phrase or sentence out of context. Repeats the same statement many times.',
            'Listen for repeated phrases or words during the session. Ask informants about speech patterns at home.'],
        [18, 3, 'Speech-Language and Communication', 'Engages in echolalic speech',
            'Repeats or echoes questions or statements made by others. May not understand they need to answer questions.',
            'Observe if the child repeats what you said either in whole or in part instead of answering.'],
        [19, 3, 'Speech-Language and Communication', 'Produces infantile squeals or unusual noises',
            'Squeals, makes bizarre noises and produces unintelligible speech-like sounds. Sounds may lack meaning.',
            'Note any unusual vocalizations during the session.'],
        [20, 3, 'Speech-Language and Communication', 'Unable to initiate or sustain conversation with others',
            'Cannot initiate or sustain a conversation.',
            'Check if the child can meaningfully respond to a series of questions or maintain a dialogue for adequate time.'],
        [21, 3, 'Speech-Language and Communication', 'Uses jargon or meaningless words',
            'Uses strange or meaningless words which convey no meaning.',
            'Listen for invented or meaningless terms during conversation.'],
        [22, 3, 'Speech-Language and Communication', 'Uses pronoun reversals',
            'Difficulty in use of pronouns. Frequently reverses pronouns such as "I" for "You".',
            'Ask the child questions that elicit "I" / "you" usage. Note any reversals.'],
        [23, 3, 'Speech-Language and Communication', 'Unable to grasp pragmatics of communication (real meaning)',
            'Difficulty understanding the true intent of speech. May not understand pragmatics. E.g. when asked "Can you tell the time?" they may say "Yes" and stop. May not understand humour or sarcasm.',
            'Test with a question like "Can you tell the time?" Note literal vs pragmatic responses. Try a simple joke or sarcasm.'],

        // ── Domain IV: Behaviour Patterns (items 24-30) ──
        [24, 4, 'Behaviour Patterns', 'Engages in stereotyped and repetitive motor mannerisms',
            'Self-stimulatory behaviour like flapping of hands or fingers, body rocking, or using an object for this purpose.',
            'Observe throughout the session for any repetitive motor behaviour — flapping, rocking, spinning etc.'],
        [25, 4, 'Behaviour Patterns', 'Shows attachment to inanimate objects',
            'Staunchly attached to certain inanimate objects which they insist on keeping — string, rock, pen, stick, toy, bottle etc.',
            'Keep all the objects on the table and check for attachment to any one. Resistance and tantrums when an attached object is taken away?'],
        [26, 4, 'Behaviour Patterns', 'Shows hyperactivity / restlessness',
            'Restless with boundless energy. Hyperactivity interferes with learning and performance of tasks.',
            'Observe activity level during the session. Ask informants about activity at home and school.'],
        [27, 4, 'Behaviour Patterns', 'Exhibits aggressive behaviour',
            'Unprovoked aggression and socially inappropriate behaviour — hitting, kicking, pinching.',
            'Ask informants for frequency, triggers, and severity of aggressive episodes.'],
        [28, 4, 'Behaviour Patterns', 'Throws temper tantrums',
            'Temper tantrums in the form of head banging, screaming, yelling. Emitted when frustrated.',
            'Ask informants about frequency and triggers of tantrums.'],
        [29, 4, 'Behaviour Patterns', 'Engages in self-injurious behaviour',
            'Self-injurious behaviours like biting, hitting or mutilating self. Requires constant supervision.',
            'Ask informants about any self-injurious behaviours, their frequency, and current management.'],
        [30, 4, 'Behaviour Patterns', 'Insists on sameness',
            'Resists change in routine. Insists things be the same. Continues the same activity. Difficult to distract from repetitive activities. Any change leads to frustration.',
            'Ask if the child wants to sit at the same place, reads the same stories, prefers the same route, wants things kept in the same place.'],

        // ── Domain V: Sensory Aspects (items 31-36) ──
        [31, 5, 'Sensory Aspects', 'Unusually sensitive to sensory stimuli',
            'Reacts strongly to certain sounds, light, touch or tastes — closing ears, eyes or refusing certain food consistencies. Actively avoids certain sensory stimuli.',
            'Ring the bell or any sound-making object. Check reactions. Touch the child gently and observe. Ask about reactions to bright light or darkness.'],
        [32, 5, 'Sensory Aspects', 'Stares into space for long periods of time',
            'Stares at some distant spot or space for long periods. Seems unaware of surroundings when so occupied.',
            'Observe periods of staring during the session. Ask informants about this at home.'],
        [33, 5, 'Sensory Aspects', 'Has difficulty in tracking objects',
            'Difficulty tracking objects or persons in motion. Unable to follow or fix gaze on moving objects/persons for required period.',
            'Throw the ball or rattle and see if the child tracks it. Move a car or spin a top and observe gaze.'],
        [34, 5, 'Sensory Aspects', 'Has unusual vision',
            'Observes tiny details which others may miss. Focuses on insignificant parts of objects that are generally ignored.',
            'Check if the child looks at miniscule parts of toys, watches from corners of eyes, or brings objects very close to the eyes.'],
        [35, 5, 'Sensory Aspects', 'Insensitive to pain',
            'Hardly reacts to pain. Not distressed or doesn\'t cry when hurt. High threshold for pain.',
            'Ask informants about reactions to injuries, falls, or medical procedures.'],
        [36, 5, 'Sensory Aspects', 'Responds to objects/people unusually by smelling, touching or tasting',
            'Explores environment by smelling, touching or tasting objects. May not show appropriate use of objects or toys.',
            'Keep all the objects out and observe if the child smells, touches or tastes them — or uses them appropriately.'],

        // ── Domain VI: Cognitive Component (items 37-40) ──
        [37, 6, 'Cognitive Component', 'Inconsistent attention and concentration',
            'Difficult to arouse attention. Does not concentrate, or concentration is on irrelevant aspects. Inconsistent in response.',
            'Ask the child to put pegs on the board, sort pieces into the sorting board, fill a bottle with beads, or string beads. Check attention/concentration.'],
        [38, 6, 'Cognitive Component', 'Shows delay in responding',
            'Does not respond to instructions promptly. Responds after considerable delay. Quick response is rarely seen.',
            'Show picture books/blocks and ask the child to point to objects. Observe response delay or need for repeated instructions.'],
        [39, 6, 'Cognitive Component', 'Has unusual memory of some kind',
            'Memory for things which most individuals would have long forgotten. Exceptional ability to remember things from the distant past.',
            'Check if the child recognizes people met long ago, remembers routines, places, dates, or names to an extraordinary extent.'],
        [40, 6, 'Cognitive Component', 'Has \'savant\' ability',
            'Special or unusual ability in some areas like reading early, mathematical feats, or artistic talent. Some show superior ability in a restricted field.',
            'Ask informants about any unusual abilities or precocious skills in specific domains.'],
    ];

    $insert = db()->prepare("INSERT OR IGNORE INTO isaa_questions
        (item_no, domain_no, domain_label, item_label, description, testing_guidance)
        VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($items as $r) {
        $insert->execute($r);
    }

    // ─────────────────────────────────────────────────────────────
    // Hindi translations of testing_guidance — for staff conducting
    // the assessment in Hindi during video/phone calls with parents.
    // Translations are conversational (asking parents directly), not literal.
    // Run as UPDATE so they apply to both fresh seeds and already-seeded rows.
    // ─────────────────────────────────────────────────────────────
    $hindi_guidance = [
        // Domain I: Social Relationship and Reciprocity
        1  => 'बच्चे को देखें कि वह आँख मिलाता है या नहीं — कितनी बार, कितनी देर, और उसकी उम्र के हिसाब से ठीक है या नहीं? माता-पिता से भी पूछें।',
        2  => 'जब बच्चा अंदर आए तो देखें — अजनबियों को देखकर मुस्कुराता है या नहीं? आपकी मुस्कान या हाथ मिलाने पर कैसी प्रतिक्रिया देता है?',
        3  => 'बच्चे से पूछें — "क्या तुम्हारे दोस्त हैं? घर पर तुम्हें कौन पसंद है? क्या खाना पसंद है?" देखें कि वह आपसे बात करते समय कितना ध्यान देता है।',
        4  => 'देखें — क्या बच्चा खुद से किसी से बात करने की कोशिश करता है? नाम लेने पर जवाब देता है या नहीं? आप उससे बातचीत करें तो कैसी प्रतिक्रिया देता है?',
        5  => 'देखें कि बच्चा लोगों से कैसे जुड़ता है। जब बात होती है तो क्या वह दूर-दूर रहता है, उदासीन लगता है?',
        6  => 'माता-पिता से पूछें — "क्या आपका बच्चा अलग-अलग जगहों पर ठीक से व्यवहार करता है? रिश्तेदारों के घर, बाज़ार, बाहर — कहीं अजीब तरह से तो नहीं करता?"',
        7  => 'पूछें — "क्या आपका बच्चा दूसरे बच्चों के साथ खेलता है, या अकेले एक ही चीज़ से बार-बार खेलता रहता है?"',
        8  => 'देखें कि बच्चा बारी लेकर खेल सकता है या नहीं — गेंद से खेलते समय बारी लेता है? बात करते समय अपनी बारी का इंतज़ार करता है?',
        9  => 'पूछें — "क्या आपका बच्चा अपनी उम्र के बच्चों के साथ खेलता है? क्या खेलता है? कितनी अच्छी तरह से घुलमिल जाता है?"',

        // Domain II: Emotional Responsiveness
        10 => 'बच्चे को देखते रहें। माता-पिता से पूछें — "क्या वह सामान्य परिस्थितियों में सही भावनाएँ दिखाता है? कभी-कभी डाँटने पर हँसता है? बिना वजह बहुत रोता या हँसता है?"',
        11 => 'जाँच के दौरान कोई अतिरंजित प्रतिक्रिया देखें। माता-पिता से उदाहरण पूछें — "क्या किसी छोटी चीज़ से बहुत डर जाता है?"',
        12 => 'पूछें — "क्या आपका बच्चा अपने आप से बात करता है, बिना कारण हँसता है, मुस्कुराता है या रोने जैसी आवाज़ें निकालता है?"',
        13 => 'माता-पिता से पूछें — "क्या आपका बच्चा खतरों को समझता है? ऊँचाई, गाड़ी, गरम चीज़, अजनबियों से डरता है या नहीं?"',
        14 => 'सेशन के दौरान बच्चे की एक्टिविटी देखें। पूछें — "घर पर कितना एक्टिव रहता है? कभी बिना वजह बहुत उत्तेजित या बेचैन तो नहीं हो जाता?"',

        // Domain III: Speech-Language and Communication
        15 => 'माता-पिता से बच्चे की बोलने की कहानी पूछें — "कब बोलना शुरू किया? कौन से शब्द बोलने लगे थे? क्या बाद में बोलना कम हो गया या बंद हो गया?"',
        16 => 'कप, गुड़िया, गाड़ी, चम्मच, चाबी एक लाइन में रखें। बच्चे से कहें कि किसी एक चीज़ की तरफ इशारा करे। देखें कि वह अपनी ज़रूरत कैसे बताता है।',
        17 => 'सेशन में सुनें — क्या वह कोई शब्द या वाक्य बार-बार बोलता है? माता-पिता से घर पर के बोलने के तरीके के बारे में पूछें।',
        18 => 'देखें — आप जो कहते हैं क्या बच्चा वही दोहराता है (पूरा या आधा), जवाब देने के बजाय?',
        19 => 'सेशन के दौरान कोई अजीब आवाज़ें सुनें — चीख़ें, अजीब-सी आवाज़ें, बिना मतलब की बोली।',
        20 => 'देखें — क्या बच्चा कई सवालों के सही जवाब दे सकता है? क्या एक ही विषय पर कुछ देर तक बातचीत कर सकता है?',
        21 => 'बातचीत में सुनें — क्या वह अजीब, बनाए हुए या बिना मतलब के शब्द इस्तेमाल करता है?',
        22 => 'बच्चे से ऐसे सवाल पूछें जिनमें "मैं" / "तुम" का इस्तेमाल हो। देखें कि कहीं वह इन्हें उल्टा तो नहीं बोलता।',
        23 => 'पूछकर देखें — "क्या तुम समय बता सकते हो?" अगर वह सिर्फ़ "हाँ" कहकर रुक जाए तो pragmatics की कमी है। एक छोटा मज़ाक या व्यंग्य भी आज़माएँ।',

        // Domain IV: Behaviour Patterns
        24 => 'पूरे सेशन में देखें — क्या बच्चा हाथ हिलाता है, झूलता है, घूमता है, या कोई भी हरकत बार-बार करता है?',
        25 => 'सारी चीज़ें टेबल पर रखें और देखें कि बच्चा किसी एक से बहुत जुड़ा हुआ तो नहीं? जब वह चीज़ हटाई जाए तो क्या बच्चा गुस्सा होता है या टैंट्रम करता है?',
        26 => 'सेशन में बच्चे की एक्टिविटी देखें। माता-पिता से पूछें — "घर और स्कूल में कितना एक्टिव रहता है?"',
        27 => 'माता-पिता से पूछें — "कितनी बार आक्रामक होता है? क्या कारण होते हैं? कितना गंभीर होता है? — मारना, लात मारना, चिकोटी काटना?"',
        28 => 'पूछें — "टैंट्रम कितनी बार आते हैं? क्या वजह होती है? — सिर पटकना, चीखना, चिल्लाना?"',
        29 => 'पूछें — "क्या बच्चा खुद को नुकसान पहुँचाता है? कितनी बार? कैसे संभालते हैं? — खुद को काटना, मारना?"',
        30 => 'पूछें — "क्या बच्चा एक ही जगह बैठना चाहता है? वही कहानी बार-बार पढ़ता है? वही रास्ता? चीज़ें हमेशा एक ही जगह? रोज़ का काम एक ही क्रम में?"',

        // Domain V: Sensory Aspects
        31 => 'घंटी या कोई आवाज़ करने वाली चीज़ बजाएँ। देखें — क्या बच्चा कान बंद कर लेता है, परेशान हो जाता है? आहिस्ता से छूकर देखें। तेज़ रोशनी या अंधेरे पर भी प्रतिक्रिया देखें।',
        32 => 'सेशन में देखें कि क्या बच्चा किसी एक जगह देर तक टकटकी लगाकर देखता है। माता-पिता से घर पर के बारे में पूछें।',
        33 => 'गेंद या खड़खड़ा फेंकें और देखें — क्या बच्चा उसे आँखों से ट्रैक करता है? गाड़ी घुमाएँ या लट्टू नचाएँ और देखें कि वह उसे देखता है या नहीं।',
        34 => 'देखें — क्या बच्चा खिलौने के किसी छोटे से हिस्से को बहुत ध्यान से देखता है? आँखों के कोनों से देखता है? चीज़ों को आँखों के बहुत पास लाकर देखता है?',
        35 => 'माता-पिता से पूछें — "क्या बच्चे को चोट लगने पर ज़्यादा प्रतिक्रिया नहीं होती? गिरने पर, इलाज के समय रोता या परेशान नहीं होता?"',
        36 => 'सारी चीज़ें सामने रखें और देखें — क्या बच्चा उन्हें सूँघता है, छूता है, चखता है? या सही तरीके से इस्तेमाल करता है?',

        // Domain VI: Cognitive Component
        37 => 'बच्चे से पेग बोर्ड में पेग लगाने को कहें, या सॉर्टिंग बोर्ड में टुकड़े सही जगह रखने को, या बोतल में मनके भरने को। देखें कि वह कितना ध्यान दे पाता है।',
        38 => 'पिक्चर बुक या ब्लॉक्स दिखाकर बच्चे से किसी चीज़ की तरफ इशारा करने को कहें। देखें — क्या वह देर से जवाब देता है? बार-बार कहना पड़ता है?',
        39 => 'पूछें — "क्या बच्चा बहुत पुरानी मुलाकात के लोगों को पहचान लेता है? पुरानी जगहें, तारीखें, समय, नाम — असाधारण रूप से याद रखता है?"',
        40 => 'माता-पिता से पूछें — "क्या आपके बच्चे में कोई असाधारण क्षमता है? जैसे जल्दी पढ़ना सीख जाना, गणित में तेज़ होना, कलात्मक प्रतिभा?"',
    ];

    $update_hi = db()->prepare("UPDATE isaa_questions SET testing_guidance_hi = ? WHERE item_no = ?");
    foreach ($hindi_guidance as $item_no => $hi_text) {
        $update_hi->execute([$hi_text, $item_no]);
    }

    // ─────────────────────────────────────────────────────────────
    // Rating profile per item — controls which rating labels to show.
    //   frequency: How often is this behaviour observed? (default — fits most items)
    //   severity:  How severely is this issue present? (graded items)
    //   presence:  Is this characteristic present, and how clearly? (yes/no-ish + ability items)
    //
    // The score values (1..5) remain unchanged across all profiles, so the
    // total score (40..200), classification, and disability % calculations are
    // not affected. Only the LABELS shown to the partner change.
    // ─────────────────────────────────────────────────────────────
    $rating_profiles = [
        // Frequency (default — most items)
        1=>'frequency',  2=>'frequency',  3=>'frequency',  4=>'frequency',
        6=>'frequency',  7=>'frequency',
        10=>'frequency', 11=>'frequency', 12=>'frequency', 14=>'frequency',
        17=>'frequency', 18=>'frequency', 19=>'frequency', 21=>'frequency', 22=>'frequency',
        24=>'frequency', 27=>'frequency', 28=>'frequency', 29=>'frequency',
        32=>'frequency', 36=>'frequency',
        37=>'frequency', 38=>'frequency',

        // Severity — graded by intensity, not frequency
        5=>'severity',   8=>'severity',   9=>'severity',
        13=>'severity',
        16=>'severity',  20=>'severity',  23=>'severity',
        25=>'severity',  26=>'severity',  30=>'severity',
        31=>'severity',  33=>'severity',  34=>'severity',  35=>'severity',

        // Presence — historical fact / ability — about whether and how clearly it exists
        15=>'presence',  39=>'presence',  40=>'presence',
    ];
    $update_rp = db()->prepare("UPDATE isaa_questions SET rating_profile = ? WHERE item_no = ?");
    foreach ($rating_profiles as $item_no => $profile) {
        $update_rp->execute([$profile, $item_no]);
    }

    // ─────────────────────────────────────────────────────────────
    // Seed catalogue entry (idempotent, only inserts if missing)
    // ─────────────────────────────────────────────────────────────
    try {
        $existing = db()->prepare("SELECT 1 FROM service_meta WHERE service_key = ?");
        $existing->execute(['mod_isaa_assessment']);
        if (!$existing->fetchColumn()) {
            db()->prepare("INSERT INTO service_meta
                (service_key, catalogue_group, tier, icon,
                 short_desc, short_desc_hi,
                 long_desc_md, long_desc_md_hi,
                 age_min, age_max, plan_weeks, free_consults_included,
                 sort_order, is_catalogue, assessment_ready)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   'mod_isaa_assessment',
                   'wellbeing',
                   'deep',
                   '🧠',
                   'Clinical autism assessment by an approved partner clinician (NIMH 40-item ISAA scale).',
                   'पार्टनर क्लिनिशियन द्वारा क्लिनिकल ऑटिज़्म मूल्यांकन (NIMH 40-आइटम ISAA स्केल)।',
                   "## ISAA Autism Assessment\n\nA standardised clinical autism assessment based on the **Indian Scale for Assessment of Autism (ISAA)** by NIMH (National Institute for the Mentally Handicapped, Govt. of India).\n\n- Conducted by an approved partner clinician via video or in-person session (20–30 minutes)\n- 40 items across 6 domains: Social, Emotional, Speech, Behaviour, Sensory, Cognitive\n- Generates a detailed report with category, disability percentage, and per-domain advice\n\n_This is a clinical screening tool, not a substitute for a formal medical diagnosis._",
                   "## ISAA ऑटिज़्म मूल्यांकन\n\nNIMH (National Institute for the Mentally Handicapped, भारत सरकार) द्वारा निर्मित **ISAA (Indian Scale for Assessment of Autism)** पर आधारित मानकीकृत क्लिनिकल ऑटिज़्म मूल्यांकन।\n\n- एक स्वीकृत पार्टनर क्लिनिशियन द्वारा वीडियो या व्यक्तिगत सत्र में किया जाता है (20–30 मिनट)\n- 6 डोमेन में 40 आइटम: सामाजिक, भावनात्मक, भाषा, व्यवहार, संवेदी, संज्ञानात्मक\n- विस्तृत रिपोर्ट में श्रेणी, विकलांगता प्रतिशत, और प्रति-डोमेन सलाह\n\n_यह एक क्लिनिकल स्क्रीनिंग टूल है, औपचारिक चिकित्सा निदान का विकल्प नहीं।_",
                   3.0,
                   17.0,
                   0,
                   0,
                   95,
                   1,
                   0,
               ]);
        }
        db()->prepare("INSERT OR REPLACE INTO service_prices (service_key, label, price, audience, is_active)
                       VALUES (?, ?, ?, ?, ?)")
           ->execute(['mod_isaa_assessment', 'ISAA Autism Assessment', 999, 'parent', 1]);
    } catch (Throwable $e) {
        error_log('[seed isaa catalogue] ' . $e->getMessage());
    }

})();
