<?php declare( strict_types=1 );

/**
 * Code style for wpify/scoper.
 *
 * This project is written in the WordPress-ish dialect its audience reads: tabs, a space inside
 * every bracket, `array()` rather than `[]`, Yoda comparisons. That is unusual for a Composer
 * plugin, but it is what the code already is, and a PSR-12 sweep would rewrite every line of
 * every file - see CONTRIBUTING.md for what that migration would actually cost.
 *
 * So this config enforces the existing style rather than replacing it. The rules below are the
 * ones that are both safe and mechanical: they never touch bracket spacing, brace placement or
 * array syntax, because PHP-CS-Fixer cannot express the WordPress variants of those.
 */

$finder = PhpCsFixer\Finder::create()
	->in( array( __DIR__ . '/src', __DIR__ . '/scripts', __DIR__ . '/config', __DIR__ . '/tests' ) )
	->append( array( __DIR__ . '/bin/wpify-scoper' ) )
	// Generated symbol tables: reformatting them starts a fight with scripts/extract-symbols.php.
	->exclude( array( 'fixtures' ) )
	->notPath( 'scoper.config.php' );

return ( new PhpCsFixer\Config() )
	->setFinder( $finder )
	->setUsingCache( true )
	->setCacheFile( __DIR__ . '/.php-cs-fixer.cache' )
	->setIndent( "\t" )
	->setLineEnding( "\n" )
	->setRiskyAllowed( true )
	->setRules( array(
		// --- whitespace and layout, matched to what the files already do ---
		'encoding'                          => true,
		'full_opening_tag'                  => true,
		'line_ending'                       => true,
		'indentation_type'                  => true,
		'no_trailing_whitespace'            => true,
		'no_trailing_whitespace_in_comment' => true,
		'single_blank_line_at_eof'          => true,
		'no_whitespace_in_blank_line'       => true,
		// Deliberately without `curly_brace_block`: the blank line after a class's opening brace
		// is part of the house style throughout src/.
		'no_extra_blank_lines'              => array( 'tokens' => array( 'square_brace_block', 'parenthesis_brace_block' ) ),
		'blank_line_after_namespace'        => true,
		'no_spaces_after_function_name'     => true,
		'no_closing_tag'                    => true,

		// --- imports ---
		'no_unused_imports'         => true,
		'ordered_imports'           => array( 'sort_algorithm' => 'alpha', 'imports_order' => array( 'class', 'function', 'const' ) ),
		'single_import_per_statement' => true,
		'no_leading_import_slash'   => true,

		// --- declarations ---
		'declare_strict_types'      => false, // Written as `<?php declare( strict_types=1 );` on line 1, which this rule reformats.
		'lowercase_keywords'        => true,
		'lowercase_static_reference' => true,
		'constant_case'             => array( 'case' => 'lower' ),
		'magic_constant_casing'     => true,
		'native_function_casing'    => true,
		'short_scalar_cast'         => true,
		'visibility_required'       => array( 'elements' => array( 'property', 'method', 'const' ) ),
		'return_type_declaration'   => array( 'space_before' => 'none' ),

		// --- comparisons: the codebase puts the constant on the left ---
		'yoda_style' => array(
			'equal'            => true,
			'identical'        => true,
			'less_and_greater' => null, // `$i < 10` reads better than `10 > $i`; leave those alone.
		),

		// --- docblocks ---
		'phpdoc_indent'                => true,
		'phpdoc_trim'                  => true,
		'phpdoc_scalar'                => true,
		'phpdoc_no_useless_inheritdoc' => true,
		'no_empty_phpdoc'              => true,
		'no_empty_comment'             => true,

		// --- deliberately NOT enabled ---
		// 'array_syntax'          => the codebase uses array(), and php-scoper configs are copied
		//                            verbatim into user projects where array() reads fine.
		// 'braces'/'curly_braces' => would move `) {` and reflow every control structure.
		// 'binary_operator_spaces' => the aligned `=` blocks are intentional and readable.
	) );
