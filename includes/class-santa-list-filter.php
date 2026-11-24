<?php
defined( 'ABSPATH' ) || exit;

/**
 * Profanity filter that catches obfuscation:
 *   - Leet speak (@ → a, 3 → e, 0 → o, $ → s, 1 → i/l, etc.)
 *   - Spaces between letters (A S S)
 *   - Underscores/dashes/dots between letters (A_S_S)
 *   - Mixed case
 *   - Repeated characters (aaass)
 *   - Unicode lookalikes
 */
class Santa_List_Filter {

    /**
     * Leet/symbol → letter map
     */
    private static $leet_map = array(
        '@'  => 'a', '4'  => 'a', '/\\'=> 'a',
        '8'  => 'b',
        '('  => 'c', '<'  => 'c', '{'  => 'c',
        ')'  => 'd',
        '3'  => 'e', '€'  => 'e',
        'ph' => 'f',
        '6'  => 'g', '9'  => 'g',
        '#'  => 'h',
        '!'  => 'i', '1'  => 'i', '|'  => 'i',
        '7'  => 't', '+'  => 't',
        '0'  => 'o',
        '$'  => 's', '5'  => 's', 'z'  => 's',
        '2'  => 'z',
        'x'  => 'ks',
        'ü'  => 'u', 'ú'  => 'u', 'û'  => 'u',
        'á'  => 'a', 'à'  => 'a', 'ä'  => 'a', 'â'  => 'a',
        'é'  => 'e', 'è'  => 'e', 'ë'  => 'e', 'ê'  => 'e',
        'í'  => 'i', 'ì'  => 'i', 'ï'  => 'i', 'î'  => 'i',
        'ó'  => 'o', 'ò'  => 'o', 'ö'  => 'o', 'ô'  => 'o',
        'ñ'  => 'n',
    );

    /**
     * Base word list — extend as needed.
     * These are matched AFTER normalization.
     */
    private static $bad_words = array(
        'ass', 'asses', 'asshole', 'assholes',
        'bastard', 'bastards',
        'bitch', 'bitches', 'bitching',
        'cock', 'cocks', 'cocksucker',
        'crap',
        'cum', 'cumshot',
        'cunt', 'cunts',
        'damn', 'damned',
        'dick', 'dicks', 'dickhead',
        'dildo',
        'dyke',
        'fag', 'faggot', 'faggots', 'fags',
        'fuck', 'fucker', 'fuckers', 'fucking', 'fucks', 'fucked',
        'hell',
        'homo',
        'jackass',
        'jerk',
        'kike',
        'motherfucker', 'motherfuckers',
        'nigger', 'niggers', 'nigga',
        'penis',
        'piss', 'pissed',
        'prick', 'pricks',
        'pussy', 'pussies',
        'retard', 'retarded',
        'shit', 'shits', 'shitty', 'bullshit',
        'slut', 'sluts',
        'spic',
        'twat', 'twats',
        'vagina',
        'whore', 'whores',
        'wop',
    );

    /**
     * Normalize a string to strip obfuscation, then check against word list.
     * Returns true if the string contains a bad word.
     */
    public static function contains_profanity( $text ) {
        $normalized = self::normalize( $text );
        foreach ( self::$bad_words as $word ) {
            if ( strpos( $normalized, $word ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize text:
     * 1. Lowercase
     * 2. Remove separators between single characters (A S S → ass, A_S_S → ass)
     * 3. Replace leet/symbols with letters
     * 4. Collapse repeated characters (aaass → as)
     * 5. Strip remaining non-alpha characters
     */
    public static function normalize( $text ) {
        $text = mb_strtolower( $text, 'UTF-8' );

        // Remove separators between single characters: "a s s", "a_s_s", "a-s-s", "a.s.s"
        // Pattern: single char followed by separator followed by single char (repeated)
        $text = preg_replace( '/\b([a-z0-9@$!])([\s_\-\.]+)(?=[a-z0-9@$!]\b)/u', '$1', $text );
        // Run twice to catch multi-word
        $text = preg_replace( '/([a-z0-9@$!])([\s_\-\.])([a-z0-9@$!])/u', '$1$3', $text );
        $text = preg_replace( '/([a-z0-9@$!])([\s_\-\.])([a-z0-9@$!])/u', '$1$3', $text );

        // Apply leet map (longest substitutions first)
        $map = self::$leet_map;
        arsort( $map ); // longest keys first to avoid partial replacements
        foreach ( $map as $leet => $letter ) {
            $text = str_replace( $leet, $letter, $text );
        }

        // Collapse repeated letters: "fuuuck" → "fuck", "aaass" → "as"
        $text = preg_replace( '/(.)\1{2,}/', '$1$1', $text );

        // Strip anything that isn't a letter or space
        $text = preg_replace( '/[^a-z\s]/u', '', $text );

        return $text;
    }

    /**
     * Filter a name: returns cleaned name or WP_Error if profanity found.
     */
    public static function filter_name( $name ) {
        if ( self::contains_profanity( $name ) ) {
            return new WP_Error(
                'profanity',
                __( 'That name contains inappropriate language and cannot be submitted.', 'santa-list' )
            );
        }
        return sanitize_text_field( $name );
    }
}
