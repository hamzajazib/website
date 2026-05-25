<?php

namespace app\modules\front\models;

use Yii;
use yii\db\ActiveRecord;

class Narrator extends ActiveRecord
{
    public static function tableName()
    {
        return 'Narrators';
    }

    /**
     * Fetch a narrator by narrator_id, with per-row caching.
     * Returns null if not found.
     */
    public static function findByNarratorId($nid)
    {
        $cacheKey = 'narrator:id:' . (int)$nid;
        $cached = Yii::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached ?: null;
        }
        $narrator = static::findOne(['narrator_id' => (int)$nid]);
        Yii::$app->cache->set($cacheKey, $narrator, Yii::$app->params['cacheTTL']);
        return $narrator;
    }

    /**
     * Returns a map of narrator_id => byname for all narrators, used to resolve
     * teacher/student ids without N+1 queries. Cached as a single blob.
     *
     * @return array  [narrator_id (int) => byname (string), ...]
     */
    public static function getSummaryMap()
    {
        $cacheKey = 'narrator:summary_map';
        $map = Yii::$app->cache->get($cacheKey);
        if ($map !== false) {
            return $map;
        }
        $rows = Yii::$app->db
            ->createCommand('SELECT narrator_id, name FROM Narrators')
            ->queryAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['narrator_id']] = $row['name'];
        }
        Yii::$app->cache->set($cacheKey, $map, Yii::$app->params['cacheTTL']);
        return $map;
    }

    /**
     * Returns the narrator_ids of this narrator's teachers.
     *
     * @return int[]
     */
    public function getTeacherIds()
    {
        if (empty($this->teachers)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $this->teachers))));
    }

    /**
     * Returns the narrator_ids of this narrator's students.
     *
     * @return int[]
     */
    public function getStudentIds()
    {
        if (empty($this->students)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $this->students))));
    }

    /**
     * Parses the critic_opinions field into structured rows.
     * The field is a JSON array of {critic_id, name, opinion} objects.
     *
     * @return array  [['name' => string, 'opinion' => string], ...]
     */
    public function getCriticOpinions()
    {
        if (empty($this->critic_opinions)) {
            return [];
        }
        $decoded = json_decode($this->critic_opinions, true);
        if (!is_array($decoded)) {
            return [];
        }
        $opinions = [];
        foreach ($decoded as $row) {
            if (!empty($row['name']) && !empty($row['opinion'])) {
                $opinions[] = [
                    'name'    => $row['name'],
                    'opinion' => $row['opinion'],
                ];
            }
        }
        return $opinions;
    }

    // ──────────────────────────────────────────────────── TRANSLITERATION

    private static function getWordDict(): array
    {
        static $dict = null;
        if ($dict !== null) {
            return $dict;
        }
        return $dict = (require __DIR__ . '/data/narrator_maps.php')['word_dict'];
    }

    private static function getCharMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        return $map = [
            'ب' => 'b',  'ت' => 't',  'ث' => 'th', 'ج' => 'j',  'ح' => 'h',  'خ' => 'kh',
            'د' => 'd',  'ذ' => 'dh', 'ر' => 'r',  'ز' => 'z',  'س' => 's',  'ش' => 'sh',
            'ص' => 's',  'ض' => 'd',  'ط' => 't',  'ظ' => 'z',  'ع' => '`a',  'غ' => 'gh',
            'ف' => 'f',  'ق' => 'q',  'ك' => 'k',  'ل' => 'l',  'م' => 'm',  'ن' => 'n',
            'ه' => 'h',  'و' => 'w',  'ي' => 'y',  'ة' => 'a',
            'ء' => "'",  'ئ' => "'",  'ؤ' => "'",
            'ا' => 'a',  'أ' => 'a',  'إ' => 'i',  'آ' => 'a',
        ];
    }

    /**
     * Transliterates an Arabic name string to simplified Latin script.
     *
     * Uses a two-tier approach: word-level dictionary for known names and
     * nisbas, with a character-level consonant map as fallback. No diacritical
     * marks are added (simplified IJMES). Structural particles (ibn, bint,
     * Abu, etc.) are handled explicitly.
     *
     * @param  string $arabicText  Raw Arabic text (tashkeel allowed; stripped internally)
     * @return string
     */
    public static function transliterateArabicName(string $arabicText): string
    {
        // Strip tashkeel (U+0610–U+061A, U+064B–U+065F) and tatweel (U+0640)
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}]/u', '', $arabicText);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $wordDict = self::getWordDict();

        $words = preg_split('/\s+/u', $text);
        $n     = count($words);
        $out   = [];

        for ($i = 0; $i < $n; $i++) {
            $w = $words[$i];

            // Structural particles
            if ($w === 'بن' || $w === 'ابن') { $out[] = 'ibn';   continue; }
            if ($w === 'بنت')                 { $out[] = 'bint';  continue; }
            if ($w === 'أبو')                 { $out[] = 'Abu';   continue; }
            if ($w === 'أبي')                 { $out[] = 'Abi';   continue; }
            if ($w === 'مولى')                { $out[] = 'mawla'; continue; }
            if ($w === 'ذو')                  { $out[] = 'Dhu';   continue; }
            if ($w === 'أم')                  { $out[] = 'Umm';   continue; }

            // عبد compound: peek ahead when followed by ال-word
            if ($w === 'عبد' && $i + 1 < $n && str_starts_with($words[$i + 1], 'ال')) {
                $next = $words[$i + 1];
                $stem = mb_substr($next, 2);
                if (isset($wordDict[$next])) {
                    $out[] = '`Abd ' . $wordDict[$next];
                } elseif (isset($wordDict[$stem])) {
                    $out[] = '`Abd al-' . $wordDict[$stem];
                } else {
                    $out[] = '`Abd al-' . self::ucfirstTranslit(self::transliterateWord($stem));
                }
                $i++;
                continue;
            }

            // Word-level dictionary
            if (isset($wordDict[$w])) {
                $out[] = $wordDict[$w];
                continue;
            }

            // Strip leading ال: try stem in dict (auto-prefix al-), else char-level
            if (str_starts_with($w, 'ال')) {
                $stem = mb_substr($w, 2);
                if (isset($wordDict[$stem])) {
                    $out[] = 'al-' . $wordDict[$stem];
                } else {
                    $out[] = 'al-' . self::ucfirstTranslit(self::transliterateWord($stem));
                }
                continue;
            }

            // Character-level fallback
            $out[] = self::transliterateWord($w);
        }

        // Capitalise first token and tokens following relational particles
        $capitalizeNext   = true;
        $particleTriggers = ['ibn', 'bint', 'mawla', 'dhu', 'abu', 'abi', 'umm'];
        for ($i = 0, $count = count($out); $i < $count; $i++) {
            $lower = mb_strtolower($out[$i]);
            if ($capitalizeNext && !str_starts_with($out[$i], 'al-')) {
                $out[$i] = self::ucfirstTranslit($out[$i]);
            }
            $capitalizeNext = in_array($lower, $particleTriggers);
        }

        return implode(' ', $out);
    }

    /**
     * Capitalises the first meaningful character of a transliterated token.
     * Tokens beginning with ` (ayn) or ' (hamza) have their second character
     * capitalised instead, since the punctuation mark is not a letter.
     */
    private static function ucfirstTranslit(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $first = mb_substr($s, 0, 1);
        if (($first === '`' || $first === "'") && mb_strlen($s) > 1) {
            return $first . mb_strtoupper(mb_substr($s, 1, 1)) . mb_substr($s, 2);
        }
        return mb_strtoupper($first) . mb_substr($s, 1);
    }

    /**
     * Character-level transliteration fallback for a single Arabic word.
     * Maps consonants via CHAR_MAP; collapses consecutive identical special
     * chars (ayn/hamza); strips trailing ayn/hamza.
     */
    private static function transliterateWord(string $word): string
    {
        $charMap  = self::getCharMap();
        $chars    = mb_str_split($word);
        $last     = count($chars) - 1;
        $result   = '';
        $prev     = '';
        foreach ($chars as $i => $ch) {
            // Word-final ي is a long-i vowel, not the consonant y
            $mapped = ($ch === 'ي' && $i === $last) ? 'i' : ($charMap[$ch] ?? $ch);
            // Collapse consecutive identical ayn or hamza
            if ($mapped === $prev && ($mapped === '`' || $mapped === "'")) {
                continue;
            }
            $result .= $mapped;
            $prev    = $mapped;
        }
        return rtrim($result, "`'");
    }

    // ──────────────────────────────────────── JARH & TA'DIL TRANSLATION

    /**
     * Lookup map of Arabic jarh_tadil phrases → curated English translations.
     * Keys are plain Arabic (no tashkeel). Covers all terms with frequency ≥ 3
     * from the acquisition dataset.
     */
    private static function getJarhTadilMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        return $map = (require __DIR__ . '/data/narrator_maps.php')['jarh_tadil'];
    }

    /**
     * Maps a numeric reliability_grade (1–12) to a CSS tier token used on the
     * pill-grade element. Returns 'neutral' for out-of-range values.
     *
     * Tier colour intent:
     *   grade-1 (1–3)  : dark green   — strongest acceptance
     *   grade-2 (4–6)  : light green  — accepted
     *   grade-3 (7)    : yellow       — middling
     *   grade-4 (8–10) : amber-red    — weak / criticised
     *   grade-5 (11–12): dark red     — rejected
     *
     * @param  int $grade  reliability_grade value from DB
     * @return string
     */
    public static function getReliabilityGradeTier(int $grade): string
    {
        if ($grade >= 1  && $grade <= 3)  return 'grade-1';
        if ($grade >= 4  && $grade <= 6)  return 'grade-2';
        if ($grade === 7)                 return 'grade-3';
        if ($grade >= 8  && $grade <= 10) return 'grade-4';
        if ($grade >= 11 && $grade <= 12) return 'grade-5';
        return 'neutral';
    }

    public static function translateJarhTadil(string $arabic): string
    {
        $stripped = trim(preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}]/u', '', $arabic));
        $map      = self::getJarhTadilMap();
        return $map[$stripped] ?? self::transliterateArabicName($arabic);
    }

    // ────────────────────────────────────────── RESIDENCE TRANSLATION

    /**
     * Lookup map of Arabic residence values → English names.
     * Keys are plain Arabic (no tashkeel). Covers all cities with frequency ≥ 5
     * from the acquisition dataset.
     */
    private static function getResidenceMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        return $map = (require __DIR__ . '/data/narrator_maps.php')['residence'];
    }

    /**
     * Translates an Arabic residence value to English using the curated map.
     * Strips tashkeel before lookup. Falls back to transliterateArabicName()
     * for values not in the map.
     *
     * @param  string $arabic  Raw Arabic residence value
     * @return string
     */
    public static function translateResidence(string $arabic): string
    {
        $map   = self::getResidenceMap();
        $parts = preg_split('/\s*،\s*/u', trim($arabic));
        $out   = [];
        foreach ($parts as $part) {
            $part     = trim($part);
            $stripped = trim(preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}]/u', '', $part));
            $out[]    = $map[$stripped] ?? self::transliterateArabicName($part);
        }
        return implode(', ', $out);
    }

    // ────────────────────────────────────────── PROFESSION TRANSLATION

    /**
     * Lookup map of Arabic profession values → English translations.
     * Keys are plain Arabic (no tashkeel). Covers all terms with frequency ≥ 10
     * from the acquisition dataset (decomposed from compound entries).
     */
    private static function getProfessionMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        return $map = (require __DIR__ . '/data/narrator_maps.php')['profession'];
    }

    /**
     * Translates an Arabic profession value to English using the curated map.
     * Handles compound values separated by ، and strips : clarifications.
     * Falls back to transliterateArabicName() for unrecognised terms.
     *
     * @param  string $arabic  Raw Arabic profession value
     * @return string
     */
    public static function translateProfession(string $arabic): string
    {
        $map   = self::getProfessionMap();
        $parts = preg_split('/\s*،\s*/u', trim($arabic));
        $out   = [];
        foreach ($parts as $part) {
            $part     = trim(preg_split('/\s*:\s*/u', $part)[0]);
            $stripped = trim(preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}]/u', '', $part));
            $out[]    = $map[$stripped] ?? self::transliterateArabicName($part);
        }
        return implode(', ', array_unique($out));
    }

    // ────────────────────────────────────────── DESCRIPTOR TRANSLATION

    private static function getDescriptorMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        return $map = (require __DIR__ . '/data/narrator_maps.php')['descriptor'];
    }

    /**
     * Translates an Arabic descriptor value to English using the curated map.
     * Splits on ، so each physical attribute is looked up independently.
     * Strips : clarifications (e.g. "الأشج : أشج بني عصر" → "الأشج").
     * Falls back to transliterateArabicName() for unrecognised terms.
     *
     * @param  string $arabic  Raw Arabic descriptor value
     * @return string
     */
    public static function translateDescriptor(string $arabic): string
    {
        $map   = self::getDescriptorMap();
        $parts = preg_split('/\s*،\s*/u', trim($arabic));
        $out   = [];
        foreach ($parts as $part) {
            $part     = trim(preg_split('/\s*:\s*/u', $part)[0]);
            $stripped = trim(preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}]/u', '', $part));
            $out[]    = $map[$stripped] ?? self::transliterateArabicName($part);
        }
        return implode(', ', array_unique($out));
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Returns labelled HTML blocks for each non-empty tarjama field.
     *
     * @return array  [['label' => string, 'html' => string], ...]
     */
    public function getTarjamaBlocks()
    {
        $fields = [
            'bio_tahdheeb' => 'تهذيب الكمال — الحافظ المزي',
            'bio_isaba'    => 'الإصابة',
            'bio_asad'     => 'أسد الغابة',
            'bio_istiab'  => 'الاستيعاب',
        ];
        $blocks = [];
        foreach ($fields as $field => $label) {
            if (!empty($this->$field)) {
                $blocks[] = [
                    'label' => $label,
                    'html'  => static::renderTarjama($this->$field),
                ];
            }
        }
        return $blocks;
    }

    /**
     * Converts raw tarjama markup into HTML.
     *
     * Markup tags:
     *   [name]...[/name]       → <p><strong>...</strong></p>
     *   [section]...[/section] → <div class="sublabel">...</div>
     *   [item]...[/item]       → accumulated into <ul class="two-col arabic">
     *   [narrator id="..."]...[/narrator]
     *                           → linked narrator mention
     *   [poem]...[/poem]       → formatted verse span
     *   [hadith-gk-id ... /]   → stripped
     *   plain text             → <p class="ar-para arabic">...</p> per line
     *
     * Leading reference number and sigla (e.g. "[5728] ع ") are stripped.
     *
     * @param  string $raw
     * @return string HTML
     */
    public static function renderTarjama($raw)
    {
        // Strip leading reference number + sigla: "[5728] ع " etc.
        $raw = trim(preg_replace('/^\[\d+\][^\[]*/', '', $raw));
        // Fix stray doubled opening bracket e.g. "[[section]" → "[section]"
        $raw = str_replace('[[', '[', $raw);

        // Escape HTML entities once for the whole text. Square brackets are
        // unaffected by htmlspecialchars, so block-level tags still work as
        // delimiters after this step. No branch below should re-escape.
        $raw = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $raw = static::renderTarjamaShortcodes($raw);

        $html      = '';
        $itemsBuf  = [];
        $inName    = false;
        $inSection = false;
        $inItem    = false;

        // Items store pre-built HTML; do not re-escape at flush time.
        $flushItems = function () use (&$itemsBuf, &$html) {
            if (empty($itemsBuf)) {
                return;
            }
            $html .= '<ul class="two-col arabic">';
            foreach ($itemsBuf as $item) {
                $html .= '<li>' . $item . '</li>';
            }
            $html .= '</ul>';
            $itemsBuf = [];
        };

        // Strip spurious [brackets] wrapping Arabic phrases (e.g. [روى عن]).
        // Safe after htmlspecialchars since [ ] are not HTML-special chars.
        // Inline tags are removed entirely so stray [/verdict] inside a [name]
        // block doesn't leave a literal "/verdict" text fragment.
        $stripInner = static function ($text) {
            $text = str_replace(['[scholar]', '[/scholar]', '[verdict]', '[/verdict]'], '', $text);
            return preg_replace('/\[([^\[\]]*)\]/u', '$1', $text);
        };

        // Apply inline scholar/verdict tags per-line, closing any span left open
        // at the line boundary so spans never cross <p> elements.
        $applyInline = static function (string $line): string {
            $openSpans = 0;
            $line = preg_replace_callback(
                '/\[(\/?)(scholar|verdict)\]/u',
                static function ($m) use (&$openSpans) {
                    if ($m[1] === '') {
                        $openSpans++;
                        return '<span class="tarjama-' . $m[2] . '">';
                    }
                    if ($openSpans > 0) {
                        $openSpans--;
                        return '</span>';
                    }
                    return '';
                },
                $line
            );
            if ($openSpans > 0) {
                $line .= str_repeat('</span>', $openSpans);
            }
            return $line;
        };

        $delimiters = '/(\[name\]|\[\/name\]|\[section\]|\[\/section\]|\[item\]|\[\/item\])/';
        $parts = preg_split($delimiters, $raw, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            switch ($part) {
                case '[name]':
                    $flushItems();
                    $inName = true;
                    break;
                case '[/name]':
                    $inName = false;
                    break;
                case '[section]':
                    $flushItems();
                    $inSection = true;
                    break;
                case '[/section]':
                    $inSection = false;
                    break;
                case '[item]':
                    $inItem = true;
                    break;
                case '[/item]':
                    $inItem = false;
                    break;
                default:
                    if ($inName) {
                        $text = trim($stripInner($part));
                        if ($text !== '') {
                            $html .= '<p class="ar-para arabic"><strong>'
                                . $text
                                . '</strong></p>';
                        }
                    } elseif ($inSection) {
                        $text = trim($stripInner($part));
                        if ($text !== '') {
                            $html .= '<div class="sublabel arabic">' . $text . '</div>';
                        }
                    } elseif ($inItem) {
                        $text = trim($stripInner($part));
                        if ($text !== '') {
                            $itemsBuf[] = static::linkifySigla($text);
                        }
                    } else {
                        $flushItems();
                        // Split on newlines so each prose line becomes its own <p>
                        $lines = array_filter(
                            array_map('trim', explode("\n", $part)),
                            'strlen'
                        );
                        foreach ($lines as $line) {
                            $line = trim($stripInner($applyInline($line)));
                            if ($line === '') {
                                continue;
                            }
                            $html .= '<p class="ar-para arabic">'
                                . static::linkifySigla($line)
                                . '</p>';
                        }
                    }
            }
        }

        $flushItems();
        return $html;
    }

    /**
     * Converts non-structural tarjama shortcodes after the text has already
     * been HTML-escaped.
     */
    private static function renderTarjamaShortcodes(string $escaped): string
    {
        $q = '(?:"|&quot;)';

        $escaped = preg_replace('/(?m)^\s*(?:\d+\s+)*\d+\s+&amp;\d+\s*/u', '', $escaped);
        $escaped = preg_replace(
            '/(?m)^\s*\d+\s+(?=\[hadith-gk-id\s+id=' . $q . '\d+' . $q . '\s*\/\])/u',
            '',
            $escaped
        );
        $escaped = preg_replace(
            '/\[hadith-gk-id\s+id=' . $q . '\d+' . $q . '\s*\/\]/u',
            '',
            $escaped
        );

        $escaped = preg_replace_callback(
            '/\[narrator\s+id=' . $q . '(\d+)' . $q . '\](.*?)\[\/narrator\]/us',
            static function ($m) {
                $id = (int)$m[1];
                return '<a href="/narrator/' . $id . '" class="tarjama-narrator-link">'
                    . $m[2]
                    . '</a>';
            },
            $escaped
        );

        $escaped = preg_replace_callback(
            '/\[poem\](.*?)\[\/poem\]/us',
            static function ($m) {
                $parts = array_map('trim', preg_split('/\[verse-sep\]/u', $m[1]));
                $parts = array_values(array_filter($parts, 'strlen'));
                if (empty($parts)) {
                    return '';
                }

                $html = '<span class="tarjama-poem" dir="rtl">';
                foreach ($parts as $index => $part) {
                    if ($index > 0) {
                        $html .= '<span class="tarjama-verse-sep" aria-hidden="true"></span>';
                    }
                    $html .= '<span class="tarjama-hemistich">' . $part . '</span>';
                }
                $html .= '</span>';
                return $html;
            },
            $escaped
        );

        return str_replace(
            '[verse-sep]',
            '<span class="tarjama-verse-sep" aria-hidden="true"></span>',
            $escaped
        );
    }

    /**
     * Wraps hadith-collection sigla (e.g. خ، م، بخ) in anchor tags or styled spans.
     * Expects $escaped to already be HTML-escaped (htmlspecialchars applied).
     */
    private static function linkifySigla($escaped)
    {
        static $map = null;
        if ($map === null) {
            $map = [
                // Two-character sigla first (prevent partial matches on single-char subset)
                'بخ' => ['/adab',     'Al-Adab Al-Mufrad'],
                'تم' => ['/shamail',  "Shama'il Muhammadiyah"],
                'خت' => null,
                'خد' => null,
                'سي' => null,
                'مد' => null,
                'عس' => null,
                'قد' => null,
                'عخ' => null,
                'صد' => null,
                'صم' => null,
                'عم' => null,
                'مق' => null,
                'حد' => null,
                // Single-character sigla
                'خ'  => ['/bukhari',  'Sahih al-Bukhari'],
                'م'  => ['/muslim',   'Sahih Muslim'],
                'د'  => ['/abudawud', 'Sunan Abi Dawud'],
                'ت'  => ['/tirmidhi', "Jami' al-Tirmidhi"],
                'س'  => ['/nasai',    "Sunan an-Nasa'i"],
                'ق'  => ['/ibnmajah', 'Sunan Ibn Majah'],
                'ع'  => null,
                '4'  => null,
                '3'  => null,
            ];
        }

        $ambiguous = ['قد', 'تم', 'مد', 'عم', 'حد', 'صد', 'صم'];
        $keys      = array_keys($map);
        $otherKeys = array_values(array_diff($keys, $ambiguous));

        $wrapSigla = static function ($m) use ($map) {
            $pre   = $m[1];
            $sigla = $m[2];
            $entry = $map[$sigla] ?? null;
            if ($entry !== null) {
                $tag = '<a href="' . $entry[0] . '" class="sigla-link" title="'
                    . htmlspecialchars($entry[1]) . '">' . $sigla . '</a>';
            } else {
                $tag = '<span class="sigla">' . $sigla . '</span>';
            }
            return $pre . $tag;
        };

        $ambiguousPat = '/((?:^|[ \t،,]))('
            . implode('|', array_map('preg_quote', $ambiguous))
            . ')(?=(?:[ \t]*[،,.\n]|$)|(?:[ \t]+(?:'
            . implode('|', array_map('preg_quote', $otherKeys))
            . ')(?=[ \t،,.\n]|$)))/um';
        $escaped = preg_replace_callback($ambiguousPat, $wrapSigla, $escaped);

        $pat = '/((?:^|[ \t،,]))('
            . implode('|', array_map('preg_quote', $otherKeys))
            . ')(?=[ \t،,.\n]|$)/um';

        return preg_replace_callback($pat, $wrapSigla, $escaped);
    }
}
