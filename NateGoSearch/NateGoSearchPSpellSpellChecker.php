<?php

require_once 'NateGoSearch/NateGoSearchSpellChecker.php';

/**
 * A spell checker to correct commonly misspelled words and phrases using 
 * the pspell extension for PHP. 
 *
 * @todo This class probably does not belong in NateGoSearch but lives here for
 *       now.
 *
 * This class adds the power of the Aspell libraries to spell checking, can be
 * used as an alternative to the light-weight NateGoSearchFileSpellChecker.
 *
 * @package   NateGoSearch
 * @copyright 2007 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class NateGoSearchPSpellSpellChecker extends NateGoSearchSpellChecker
{
	// {{{ private properties

	/**
	 * The dictionay for this spell checker
	 *
	 * The dictionary used to check words against for this spell checker
	 *
	 * @var integer
	 */
	$dictionary;

	// }}}
	// {{{ public function __construct()

	/**
	 * Creates a new PSpell spell checker
	 *
	 * @param string $language the language that this spell checker will be
	 *                          used the correct.
	 */
	public function __construct($language)
	{
		if (!extension_loaded('pspell'))
		{
			throw Exception();
		}

		// TODO: work in the other arguments for the pspell_new() function
		$this->dictionary = pspell_new($language);
	}

	// }}}
	// {{{ public function &getMispellingsInPhrase()

	/**
	 * Checks each word of a phrase for mispellings
	 *
	 * @param string $phrase the phrase to check
	 *
	 * @return array a list of mispelled words in the given phrase. The array is
	 *                in the form of incorrect => array(possible corrections)
	 */
	public function &getMispellingsInPhrase($phrase)
	{
		$misspellings = array();

		$exp_phrase = explode(' ', $phrase);

		foreach ($exp_phrase as $word) {
			if (!pspell_check($word))
				$misspellings[$word] = pspell_suggest($word);
		}

		return $misspellings;
	}

	// }}}			
	// {{{ public function getProperSpelling()

	/**
	 * Gets a phrase with all its misspelled words corrected
	 *
	 * @param string $phrase the phrase to correct misspellings in.
	 *
	 * @return string corrected phrase.
	 */
	public function getProperSpelling($phrase)
	{
		$phrase = ' '.$phrase.' ';

		$misspellings = $this->getMisspellingsInPhrase($phrase);

		// uses the first suggestion given by aspell as the replacement word
		foreach ($misspellings as $incorrect => $correct)
			$phrase =
				str_replace(' '.$incorrect.' ', ' '.$correct[0].' ', $phrase);

		$phrase = trim($phrase);

		return $phrase;
	}

	// }}}
}

?>
