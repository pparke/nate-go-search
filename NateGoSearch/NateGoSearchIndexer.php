<?php

/* vim: set noexpandtab tabstop=4 shiftwidth=4 foldmethod=marker: */

require_once 'MDB2.php';
require_once 'NateGoSearch.php';
require_once 'NateGoSearch/NateGoSearchTerm.php';
require_once 'NateGoSearch/NateGoSearchDocument.php';
require_once 'NateGoSearch/NateGoSearchKeyword.php';
require_once 'NateGoSearch/NateGoSearchPSpellSpellChecker.php';
require_once 'NateGoSearch/exceptions/NateGoSearchDBException.php';
require_once 'NateGoSearch/exceptions/NateGoSearchDocumentTypeException.php';

/**
 * Indexes documents using the NateGo search algorithm
 *
 * If the PECL <i>stem</i> package is loaded, English stemming is applied to all
 * indexed keywords. See {@link http://pecl.php.net/package/stem/} for details
 * about the PECL stem package. Support for stemming in other languages may
 * be added in later releases of NateGoSearch.
 *
 * Otherwise, if a PorterStemmer class is defined, it is applied to all indexed
 * keywords. The most commonly available PHP implementation of the
 * Porter-stemmer algorithm is licenced under the GPL, and is thus not
 * distributable with the LGPL licensed NateGoSearch.
 *
 * @package   NateGoSearch
 * @copyright 2006-2007 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class NateGoSearchIndexer
{
	// {{{ protected properties

	/**
	 * A list of search terms to index documents by
	 *
	 * @var array NateGoSearchTerm
	 */
	protected $terms = array();

	/**
	 * An array of words to not index
	 *
	 * These words will be skipped by the indexer. Common examples of such
	 * words are: a, the, it
	 *
	 * @var array
	 */
	protected $unindexed_words = array();

	/**
	 * The maximum length of words that are indexed
	 *
	 * If the word length is set as null, there is no maximum word length. This
	 * is hte default behaviour. If a word is longer than the maximum length,
	 * it is truncated before being indexed.
	 *
	 * @var integer
	 */
	protected $max_word_length;

	/**
	 * The name of the database table the NateGoSearch index is stored in
	 *
	 * @todo Add setter method.
	 *
	 * @var string
	 */
	protected $index_table = 'NateGoSearchIndex';

	/**
	 * An array of keywords collected from the current index operation
	 *
	 * @var array NateGoSearchKeyword
	 */
	protected $keywords = array();

	/**
	 * A list of document ids we are indexing in the current operation
	 *
	 * When commit is called, indexed entries for these ids are removed from
	 * the index. The reason is because we are reindexing these documents.
	 *
	 * @var array
	 */
	protected $clear_document_ids = array();

	/**
	 * The document type to index by
	 *
	 * Document types are a unique identifier for search indexes. NateGoSearch
	 * stores all indexed words in the same index with a document type to
	 * identify what index the word belongs to. Document types allow the
	 * possiblilty of mixed search results ordered by relavence. For example,
	 * if you seach for "roses" you could get product results, category results
	 * and article results all in the same list of search results.
	 *
	 * @var mixed
	 */
	protected $document_type;

	/**
	 * The database connection used by this indexer
	 *
	 * @var MDB2_Driver_Common
	 */
	protected $db;

	/**
	 * Whether or not the old index is cleared when changes to the index are
	 * comitted
	 *
	 * @var boolean
	 *
	 * @see NateGoSearchIndexer::__construct()
	 * @see NateGoSearchIndexer::commit()
	 * @see NateGoSearchIndexer::clear()
	 */
	protected $new = false;

	/**
	 * Whether or not keywords for indexed documents are appended to existing
	 * keywords
	 *
	 * @var boolean
	 *
	 * @see NateGoSearchIndexer::__construct()
	 * @see NateGoSearchIndexer::commit()
	 */
	protected $append = false;

	/**
	 * The spell checker for this indexer
	 *
	 * @var NateGoSearchPSpellSpellChecker
	 */
	protected $spell_checker;

	/**
	 * The words in the personal wordlist
	 *
	 * An array to hold everyword that is added to the personal wordlist
	 *
	 * @var array()
	 */
	protected $personal_wordlist = array();

	/**
	 * Whether or not a custom wordlist should be created
	 *
	 * @var boolean
	 */
	protected $create_wordlist = false;

	// }}}
	// {{{ public function __construct()

	/**
	 * Creates a search indexer with the given document type
	 *
	 * @param string $document_type the shortname of the document type to
	 *                               index by.
	 * @param MDB2_Driver_Common $db the database connection used by this
	 *                                indexer.
	 * @param boolean $new if true, this is a new search index and all indexed
	 *                      words for the given document type are removed. If
	 *                      false, we are appending to an existing index.
	 *                      Defaults to false.
	 * @param boolean $append if true, keywords keywords for documents that
	 *                         are indexed are appended to the keywords that
	 *                         may already exist for the document in the index.
	 *                         Defaults to false.
	 *
	 * @see NateGoSearch::createDocumentType()
	 *
	 * @throws NateGoSearchDocumentTypeException if the document type shortname
	 *                                           does not exist.
	 */
	public function __construct($document_type, MDB2_Driver_Common $db,
		$new = false, $append = false)
	{
		$type = NateGoSearch::getDocumentType($db, $document_type);

		if ($type === null) {
			throw new NateGoSearchDocumentTypeException(
				"Document type {$document_type} does not exist and cannot be ".
				"indexed. Document types must be created before being used.",
				0, $document_type);
		}

		$this->document_type = $type;
		$this->db = $db;
		$this->new = $new;
		$this->append = $append;
	}

	// }}}
	// {{{ public function setMaximumWordLength()

	/**
	 * Sets the maximum length of words in the index
	 *
	 * @param integer $length the maximum length of words in the index.
	 *
	 * @see NateGoSearchIndexer::$max_word_length
	 */
	public function setMaximumWordLength($length)
	{
		$this->max_word_length = ($length === null) ? null : (integer)$length;
	}

	// }}}
	// {{{ public function addTerm()

	/**
	 * Adds a search term to this index
	 *
	 * Adding a term creates index entries for the words in the document
	 * matching the term. Index terms may have different weights.
	 *
	 * @param NateGoSearchTerm $term the term to add.
	 *
	 * @see NateGoSearchTerm
	 */
	public function addTerm(NateGoSearchTerm $term)
	{
		$this->terms[] = $term;
	}

	// }}}
	// {{{ public function index()

	/**
	 * Indexes a document
	 *
	 * The document is indexed for all the terms of this indexer.
	 *
	 * @param NateGoSearchDocument $document the document to index.
	 *
	 * @see NateGoSearchDocument
	 */
	public function index(NateGoSearchDocument $document)
	{
		// word location counter
		$location = 0;

		$id = $document->getId();
		if (!$this->append && !in_array($id, $this->clear_document_ids))
			$this->clear_document_ids[] = $id;

		foreach ($this->terms as $term) {
			$text = $document->getField($term->getDataField());
			$text = self::formatKeywords($text);

			$tok = strtok($text, ' ');
			while ($tok !== false) {
				$keyword = self::stemKeyword($tok);

				if (!in_array($keyword, $this->unindexed_words)) {
					$location++;
					if ($this->max_word_length !== null &&
						strlen($keyword) > $this->max_word_length)
						$keyword = substr($keyword, 0, $this->max_word_length);

					$this->keywords[] = new NateGoSearchKeyword($keyword, $id,
						$term->getWeight(), $location, $this->document_type);
				}

				// add any words that the spell checker would flag to a
				// personal wordlist
				if ($this->create_wordlist) {
					$new_tok = $this->spell_checker->getProperSpelling($tok);

					if (($new_tok != $tok) &&
						!in_array($tok, $this->personal_wordlist) &&
						ctype_alpha($tok)) {
							$this->personal_wordlist[] = $tok;
							$this->spell_checker->addToPersonalWordlist($tok);
					}
				}

				$tok = strtok(' ');
			}
		}
	}

	// }}}
	// {{{ public function commit()

	/**
	 * Commits keywords indexed by this indexer to the database index table
	 *
	 * If this indexer was created with the 'new' parameter then the index is
	 * cleared for this indexer's document type before new keywords are
	 * inserted. Otherwise, the new keywords are simply appended to the index.
	 */
	public function commit()
	{
		try {
			$this->db->beginTransaction();

			if ($this->new) {
				$this->clear();
				$this->new = false;
			}

			$indexed_ids =
				$this->db->implodeArray($this->clear_document_ids, 'integer');

			$delete_sql = sprintf('delete from %s
				where document_id in (%s) and document_type = %s',
				$this->db->quoteIdentifier($this->index_table),
				$indexed_ids,
				$this->db->quote($this->document_type, 'integer'));

			$result = $this->db->exec($delete_sql);
			if (MDB2::isError($result))
				throw new NateGoSearchDBException($result);

			$keyword = array_pop($this->keywords);
			while ($keyword !== null) {
				$sql = sprintf('insert into %s
					(document_id, word, weight, location, document_type) values
					(%s, %s, %s, %s, %s)',
					$this->db->quoteIdentifier($this->index_table),
					$this->db->quote($keyword->getDocumentId(), 'integer'),
					$this->db->quote($keyword->getWord(), 'text'),
					$this->db->quote($keyword->getWeight(), 'integer'),
					$this->db->quote($keyword->getLocation(), 'integer'),
					$this->db->quote($keyword->getDocumentType(), 'integer'));

				$result = $this->db->exec($sql);
				if (MDB2::isError($result))
					throw new NateGoSearchDBException($result);

				$keyword = array_pop($this->keywords);
			}

			$this->clear_document_ids = array();

			$this->db->commit();
		} catch (NateGoSearchDBException $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	// }}}
	// {{{ public function addUnindexedWords()

	/**
	 * Adds words to the list of words that are not to be indexed
	 *
	 * These may be words such as 'the', 'and' and 'a'.
	 *
	 * @param string|array $words the list of words not to be indexed.
	 */
	public function addUnindexedWords($words)
	{
		if (!is_array($words))
			$words = array((string)$words);

		$this->unindexed_words = array_merge($this->unindexed_words, $words);
	}

	// }}}
	// {{{ public function setSpellChecker()

	/**
	 * Set the spell checker to be used by the indexer
	 *
	 * @param NateGoSearchPSpellChecker
	 */
	public function setSpellChecker(NateGoSearchPSpellSpellChecker $checker)
	{
		$this->create_wordlist = true;
		$this->spell_checker = $checker;
	}

	// }}}
	// {{{ public static function formatKeywords()

	/**
	 * Filters a string to prepare if for indexing
	 *
	 * This removes excess punctuation and markup, and lowercases all words.
	 * The resulting string may then be tokenized by spaces.
	 *
	 * @param string $text the string to be filtered.
	 *
	 * @return string the filtered string.
	 */
	public static function formatKeywords($text)
	{
		$text = strtolower($text);

		// replace html/xhtml/xml tags with spaces
		$text = preg_replace('@</?[^>]*>*@u', ' ', $text);

		// remove entities
		$text = html_entity_decode($text, ENT_COMPAT, 'UTF-8');
		$text = htmlspecialchars($text, ENT_COMPAT, 'UTF-8');

		// replace apostrophe s's
		$text = preg_replace('/\'s\b/u', '', $text);

		// remove punctuation at the beginning and end of the string
		$text = preg_replace('/^\W+/u', '', $text);
		$text = preg_replace('/\W+$/u', '', $text);

		// remove punctuation at the beginning and end of words
		$text = preg_replace('/\s+\W+/u', ' ', $text);
		$text = preg_replace('/\W+\s+/u', ' ', $text);

		// replace multiple dashes with a single dash
		$text = preg_replace('/-+/u', '-', $text);

		// replace whitespace with single spaces
		$text = preg_replace('/\s+/u', ' ', $text);

		return $text;
	}

	// }}}
	// {{{ public static function stemKeyword()

	/**
	 * Stems a keyword
	 *
	 * The basic idea behind stemmming is described on the Wikipedia article on
	 * {@link http://en.wikipedia.org/wiki/Stemming Stemming}.
	 *
	 * If the PECL <i>stem</i> package is loaded, English stemming is performed
	 * on the <i>$keyword</i>. See {@link http://pecl.php.net/package/stem/}
	 * for details about the PECL stem package.
	 *
	 * Otherwise, if a PorterStemmer class is defined, it is applied to the
	 * <i>$keyword</i>. The most commonly available PHP implementation of the
	 * Porter-stemmer algorithm is licenced under the GPL, and is thus not
	 * distributable with the LGPL licensed NateGoSearch.
	 *
	 * If no stemming is available, stemming is not performed and the original
	 * keyword is returned.
	 *
	 * @param string $keyword the keyword to stem.
	 *
	 * @return string the stemmed keyword.
	 */
	public static function stemKeyword($keyword)
	{
		if (extension_loaded('stem'))
			$keyword = stem($keyword, STEM_ENGLISH);
		elseif (is_callable(array('PorterStemmer', 'Stem')))
			$keyword = PorterStemmer::Stem($keyword);

		return $keyword;
	}

	// }}}
	// {{{ public static function &getDefaultUnindexedWords()

	/**
	 * Gets a defalt list of words that are not indexed by a search indexer
	 *
	 * These words may be passed directly to the
	 * {@link NateGoSearchIndexer::addUnindexedWords()} method.
	 *
	 * @return array a default list of words not to index.
	 */
	public static function &getDefaultUnindexedWords()
	{
		static $words = array();

		if (count($words) == 0) {
			if (substr('@DATA-DIR@', 0, 1) === '@')
				$filename = dirname(__FILE__).'/../system/blocked-words.txt';
			else
				$filename = '@DATA-DIR@/NateGoSearch/system/blocked-words.txt';

			$words = file($filename, true);
			// remove line breaks
			$words = array_map('rtrim', $words);
		}

		return $words;
	}

	// }}}
	// {{{ protected function clear()

	/**
	 * Clears this search index
	 *
	 * The index is cleared for this indexer's document type
	 *
	 * @see NateGoSearchIndexer::__construct()
	 *
	 * @throws NateGoSearchDBException if a database error occurs.
	 */
	protected function clear()
	{
		$sql = sprintf('delete from %s where document_type = %s',
			$this->db->quoteIdentifier($this->index_table),
			$this->db->quote($this->document_type, 'integer'));

		$result = $this->db->exec($sql);
		if (MDB2::isError($result))
			throw new NateGoSearchDBException($result);
	}

	// }}}
	// {{{ protected function __finalize()

	/**
	 * Class finalizer calls commit() automatically
	 *
	 * @see NateGoSearchIndexer::commit()
	 */
	protected function __finalize()
	{
		$this->commit();
	}

	// }}}
}

?>
