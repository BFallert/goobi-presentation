<?php
namespace Kitodo\Dlf\Common;

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * List class for the 'dlf' extension
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @author Frank Ulrich Weber <fuw@zeutschel.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class DocumentList implements \ArrayAccess, \Countable, \Iterator, \TYPO3\CMS\Core\SingletonInterface {
    /**
     * This holds the number of documents in the list
     * @see \Countable
     *
     * @var	integer
     * @access protected
     */
    protected $count = 0;

    /**
     * This holds the list entries in sorted order
     * @see \ArrayAccess
     *
     * @var	array
     * @access protected
     */
    protected $elements = [];

    /**
     * This holds the list's metadata
     *
     * @var	array
     * @access protected
     */
    protected $metadata = [];

    /**
     * This holds the current list position
     * @see \Iterator
     *
     * @var	integer
     * @access protected
     */
    protected $position = 0;

    /**
     * This holds the full records of already processed list elements
     *
     * @var	array
     * @access protected
     */
    protected $records = [];

    /**
     * Instance of \Kitodo\Dlf\Common\Solr class
     *
     * @var	\Kitodo\Dlf\Common\Solr
     * @access protected
     */
    protected $solr;

    /**
     * This holds the Solr metadata configuration
     *
     * @var	array
     * @access protected
     */
    protected $solrConfig = [];

    /**
     * This adds an array of elements at the given position to the list
     *
     * @access public
     *
     * @param array $elements: Array of elements to add to list
     * @param integer $position: Numeric position for including
     *
     * @return void
     */
    public function add(array $elements, $position = -1) {
        $position = \TYPO3\CMS\Core\Utility\MathUtility::forceIntegerInRange($position, 0, $this->count, $this->count);
        if (!empty($elements)) {
            array_splice($this->elements, $position, 0, $elements);
            $this->count = count($this->elements);
        }
    }

    /**
     * This counts the elements
     * @see \Countable::count()
     *
     * @access public
     *
     * @return integer The number of elements in the list
     */
    public function count() {
        return $this->count;
    }

    /**
     * This returns the current element
     * @see \Iterator::current()
     *
     * @access public
     *
     * @return array The current element
     */
    public function current() {
        if ($this->valid()) {
            return $this->getRecord($this->elements[$this->position]);
        } else {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->current()] Invalid position "'.$this->position.'" for list element', $this->extKey, SYSLOG_SEVERITY_NOTICE);
            }
            return;
        }
    }

    /**
     * This returns the full record of any list element
     *
     * @access protected
     *
     * @param mixed $element: The list element
     *
     * @return mixed The element's full record
     */
    protected function getRecord($element) {
        if (is_array($element)
            && array_keys($element) == ['u', 'h', 's', 'p']) {
            // Return already processed record if possible.
            if (!empty($this->records[$element['u']])) {
                return $this->records[$element['u']];
            }
            $record = [
                'uid' => $element['u'],
                'page' => 1,
                'preview' => '',
                'subparts' => $element['p']
            ];
            // Check if it's a list of database records or Solr documents.
            if (!empty($this->metadata['options']['source'])
                && $this->metadata['options']['source'] == 'collection') {
                // Get document's thumbnail and metadata from database.
                $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                    'tx_dlf_documents.uid AS uid,tx_dlf_documents.thumbnail AS thumbnail,tx_dlf_documents.metadata AS metadata',
                    'tx_dlf_documents',
                    '(tx_dlf_documents.uid='.intval($record['uid']).' OR tx_dlf_documents.partof='.intval($record['uid']).')'
                        .Helper::whereClause('tx_dlf_documents'),
                    '',
                    '',
                    ''
                );
                // Process results.
                while ($resArray = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    // Prepare document's metadata.
                    $metadata = unserialize($resArray['metadata']);
                    if (!empty($metadata['type'][0])
                        && \TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($metadata['type'][0])) {
                        $metadata['type'][0] = Helper::getIndexName($metadata['type'][0], 'tx_dlf_structures', $this->metadata['options']['pid']);
                    }
                    if (!empty($metadata['owner'][0])
                        && \TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($metadata['owner'][0])) {
                        $metadata['owner'][0] = Helper::getIndexName($metadata['owner'][0], 'tx_dlf_libraries', $this->metadata['options']['pid']);
                    }
                    if (!empty($metadata['collection'])
                        && is_array($metadata['collection'])) {
                        foreach ($metadata['collection'] as $i => $collection) {
                            if (\TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($collection)) {
                                $metadata['collection'][$i] = Helper::getIndexName($metadata['collection'][$i], 'tx_dlf_collections', $this->metadata['options']['pid']);
                            }
                        }
                    }
                    // Add metadata to list element.
                    if ($resArray['uid'] == $record['uid']) {
                        $record['thumbnail'] = $resArray['thumbnail'];
                        $record['metadata'] = $metadata;
                    } elseif (($key = array_search(['u' => $resArray['uid']], $record['subparts'], TRUE)) !== FALSE) {
                        $record['subparts'][$key] = [
                            'uid' => $resArray['uid'],
                            'page' => 1,
                            'preview' => (!empty($record['subparts'][$key]['h']) ? $record['subparts'][$key]['h'] : ''),
                            'thumbnail' => $resArray['thumbnail'],
                            'metadata' => $metadata
                        ];
                    }
                }
            } elseif (!empty($this->metadata['options']['source'])
                && $this->metadata['options']['source'] == 'search') {
                if ($this->solrConnect()) {
                    $params = [];
                    // Restrict the fields to the required ones
                    $params['fields'] = 'uid,id,toplevel,thumbnail,page';
                    foreach ($this->solrConfig as $solr_name) {
                        $params['fields'] .= ','.$solr_name;
                    }
                    // If it is a fulltext search, enable highlighting.
                    if ($this->metadata['fulltextSearch']) {
                        $params['component'] = [
                            'highlighting' => [
                                'query' => Solr::escapeQuery($this->metadata['searchString']),
                                'field' => 'fulltext',
                                'usefastvectorhighlighter' => TRUE
                            ]
                        ];
                    }
                    // Set additional query parameters.
                    $params['start'] = 0;
                    // Set reasonable limit for safety reasons.
                    // We don't expect to get more than 10.000 hits per UID.
                    $params['rows'] = 10000;
                    // Take over existing filter queries.
                    $params['filterquery'] = isset($this->metadata['options']['params']['filterquery']) ? $this->metadata['options']['params']['filterquery'] : [];
                    // Extend filter query to get all documents with the same UID.
                    foreach ($params['filterquery'] as $key => $value) {
                        if (isset($value['query'])) {
                            $params['filterquery'][$key]['query'] = $value['query'].' OR toplevel:true';
                        }
                    }
                    // Add filter query to get all documents with the required uid.
                    $params['filterquery'][] = ['query' => 'uid:'.Solr::escapeQuery($record['uid'])];
                    // Add sorting.
                    $params['sort'] = $this->metadata['options']['params']['sort'];
                    // Set query.
                    $params['query'] = $this->metadata['options']['select'].' OR toplevel:true';
                    // Perform search for all documents with the same uid that either fit to the search or marked as toplevel.
                    $selectQuery = $this->solr->service->createSelect($params);
                    $result = $this->solr->service->select($selectQuery);
                    // If it is a fulltext search, fetch the highlighting results.
                    if ($this->metadata['fulltextSearch']) {
                        $highlighting = $result->getHighlighting();
                    }
                    // Process results.
                    foreach ($result as $resArray) {
                        // Prepare document's metadata.
                        $metadata = [];
                        foreach ($this->solrConfig as $index_name => $solr_name) {
                            if (!empty($resArray->$solr_name)) {
                                $metadata[$index_name] = (is_array($resArray->$solr_name) ? $resArray->$solr_name : [$resArray->$solr_name]);
                            }
                        }
                        // Add metadata to list elements.
                        if ($resArray->toplevel) {
                            $record['thumbnail'] = $resArray->thumbnail;
                            $record['metadata'] = $metadata;
                        } else {
                            $highlightedDoc = !empty($highlighting) ? $highlighting->getResult($resArray->id) : NULL;
                            $highlight = !empty($highlightedDoc) ? $highlightedDoc->getField('fulltext')[0] : '';
                            $record['subparts'][$resArray->id] = [
                                'uid' => $resArray->uid,
                                'page' => $resArray->page,
                                'preview' => $highlight,
                                'thumbnail' => $resArray->thumbnail,
                                'metadata' => $metadata
                            ];
                        }
                    }
                }
            }
            // Save record for later usage.
            $this->records[$element['u']] = $record;
        } else {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->getRecord([data])] No UID of list element to fetch full record', $this->extKey, SYSLOG_SEVERITY_NOTICE, $element);
            }
            $record = $element;
        }
        return $record;
    }

    /**
     * This returns the current position
     * @see \Iterator::key()
     *
     * @access public
     *
     * @return integer The current position
     */
    public function key() {
        return $this->position;
    }

    /**
     * This moves the element at the given position up or down
     *
     * @access public
     *
     * @param integer $position: Numeric position for moving
     * @param integer $steps: Amount of steps to move up or down
     *
     * @return void
     */
    public function move($position, $steps) {
        // Save parameters for logging purposes.
        $_position = $position;
        $_steps = $steps;
        $position = intval($position);
        // Check if list position is valid.
        if ($position < 0
            || $position >= $this->count) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->move('.$_position.', '.$_steps.')] Invalid position "'.$position.'" for element moving', $this->extKey, SYSLOG_SEVERITY_WARNING);
            }
            return;
        }
        $steps = intval($steps);
        // Check if moving given amount of steps is possible.
        if (($position + $steps) < 0
            || ($position + $steps) >= $this->count) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->move('.$_position.', '.$_steps.')] Invalid steps "'.$steps.'" for moving element at position "'.$position.'"', $this->extKey, SYSLOG_SEVERITY_WARNING);
            }
            return;
        }
        $element = $this->remove($position);
        $this->add([$element], $position + $steps);
    }

    /**
     * This moves the element at the given position up
     *
     * @access public
     *
     * @param integer $position: Numeric position for moving
     *
     * @return void
     */
    public function moveUp($position) {
        $this->move($position, -1);
    }

    /**
     * This moves the element at the given position down
     *
     * @access public
     *
     * @param integer $position: Numeric position for moving
     *
     * @return void
     */
    public function moveDown($position) {
        $this->move($position, 1);
    }

    /**
     * This increments the current list position
     * @see \Iterator::next()
     *
     * @access public
     *
     * @return void
     */
    public function next() {
        $this->position++;
    }

    /**
     * This checks if an offset exists
     * @see \ArrayAccess::offsetExists()
     *
     * @access public
     *
     * @param mixed $offset: The offset to check
     *
     * @return boolean Does the given offset exist?
     */
    public function offsetExists($offset) {
        return isset($this->elements[$offset]);
    }

    /**
     * This returns the element at the given offset
     * @see \ArrayAccess::offsetGet()
     *
     * @access public
     *
     * @param mixed $offset: The offset to return
     *
     * @return array The element at the given offset
     */
    public function offsetGet($offset) {
        if ($this->offsetExists($offset)) {
            return $this->getRecord($this->elements[$offset]);
        } else {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->offsetGet('.$offset.')] Invalid offset "'.$offset.'" for list element', $this->extKey, SYSLOG_SEVERITY_NOTICE);
            }
            return;
        }
    }

    /**
     * This sets the element at the given offset
     * @see \ArrayAccess::offsetSet()
     *
     * @access public
     *
     * @param mixed $offset: The offset to set (non-integer offsets will be appended)
     * @param mixed $value: The value to set
     *
     * @return void
     */
    public function offsetSet($offset, $value) {
        if (\TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($offset)) {
            $this->elements[$offset] = $value;
        } else {
            $this->elements[] = $value;
        }
        // Re-number the elements.
        $this->elements = array_values($this->elements);
        $this->count = count($this->elements);
    }

    /**
     * This removes the element at the given position from the list
     *
     * @access public
     *
     * @param integer $position: Numeric position for removing
     *
     * @return array The removed element
     */
    public function remove($position) {
        // Save parameter for logging purposes.
        $_position = $position;
        $position = intval($position);
        if ($position < 0
            || $position >= $this->count) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->remove('.$_position.')] Invalid position "'.$position.'" for element removing', $this->extKey, SYSLOG_SEVERITY_WARNING);
            }
            return;
        }
        $removed = array_splice($this->elements, $position, 1);
        $this->count = count($this->elements);
        return $this->getRecord($removed[0]);
    }

    /**
     * This removes elements at the given range from the list
     *
     * @access public
     *
     * @param integer $position: Numeric position for start of range
     * @param integer $length: Numeric position for length of range
     *
     * @return array The indizes of the removed elements
     */
    public function removeRange($position, $length) {
        // Save parameter for logging purposes.
        $_position = $position;
        $position = intval($position);
        if ($position < 0
            || $position >= $this->count) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->remove('.$_position.')] Invalid position "'.$position.'" for element removing', $this->extKey, SYSLOG_SEVERITY_WARNING);
            }
            return;
        }
        $removed = array_splice($this->elements, $position, $length);
        $this->count = count($this->elements);
        return $removed;
    }

    /**
     * This clears the current list
     *
     * @access public
     *
     * @return void
     */
    public function reset() {
        $this->elements = [];
        $this->records = [];
        $this->metadata = [];
        $this->count = 0;
        $this->position = 0;
    }

    /**
     * This resets the list position
     * @see \Iterator::rewind()
     *
     * @access public
     *
     * @return void
     */
    public function rewind() {
        $this->position = 0;
    }

    /**
     * This saves the current list
     *
     * @access public
     *
     * @param integer $pid: PID for saving in database
     *
     * @return void
     */
    public function save($pid = 0) {
        $pid = max(intval($pid), 0);
        // If no PID is given, save to the user's session instead
        if ($pid > 0) {
            // TODO: Liste in Datenbank speichern (inkl. Sichtbarkeit, Beschreibung, etc.)
        } else {
            Helper::saveToSession([$this->elements, $this->metadata], get_class($this));
        }
    }

    /**
     * Connects to Solr server.
     *
     * @access protected
     *
     * @return boolean TRUE on success or FALSE on failure
     */
    protected function solrConnect() {
        // Get Solr instance.
        if (!$this->solr) {
            // Connect to Solr server.
            if ($this->solr = Solr::getInstance($this->metadata['options']['core'])) {
                // Load index configuration.
                $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                    'tx_dlf_metadata.index_name AS index_name,tx_dlf_metadata.index_tokenized AS index_tokenized,tx_dlf_metadata.index_indexed AS index_indexed',
                    'tx_dlf_metadata',
                    'tx_dlf_metadata.is_listed=1'
                        .' AND tx_dlf_metadata.pid='.intval($this->metadata['options']['pid'])
                        .Helper::whereClause('tx_dlf_metadata'),
                    '',
                    'tx_dlf_metadata.sorting ASC',
                    ''
                );
                while ($resArray = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    $this->solrConfig[$resArray['index_name']] = $resArray['index_name'].'_'.($resArray['index_tokenized'] ? 't' : 'u').'s'.($resArray['index_indexed'] ? 'i' : 'u');
                }
                // Add static fields.
                $this->solrConfig['type'] = 'type';
            } else {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * This sorts the current list by the given field
     *
     * @access public
     *
     * @param string $by: Sort the list by this field
     * @param boolean $asc: Sort ascending?
     *
     * @return void
     */
    public function sort($by, $asc = TRUE) {
        $newOrder = [];
        $nonSortable = [];
        foreach ($this->elements as $num => $element) {
            // Is this element sortable?
            if (!empty($element['s'][$by])) {
                $newOrder[$element['s'][$by].str_pad($num, 6, '0', STR_PAD_LEFT)] = $element;
            } else {
                $nonSortable[] = $element;
            }
        }
        // Reorder elements.
        if ($asc) {
            ksort($newOrder, SORT_LOCALE_STRING);
        } else {
            krsort($newOrder, SORT_LOCALE_STRING);
        }
        // Add non sortable elements to the end of the list.
        $newOrder = array_merge(array_values($newOrder), $nonSortable);
        // Check if something is missing.
        if ($this->count == count($newOrder)) {
            $this->elements = $newOrder;
        } else {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->sort('.$by.', ['.($asc ? 'TRUE' : 'FALSE').'])] Sorted list elements do not match unsorted elements', $this->extKey, SYSLOG_SEVERITY_ERROR);
            }
        }
    }

    /**
     * This unsets the element at the given offset
     * @see \ArrayAccess::offsetUnset()
     *
     * @access public
     *
     * @param mixed $offset: The offset to unset
     *
     * @return void
     */
    public function offsetUnset($offset) {
        unset ($this->elements[$offset]);
        // Re-number the elements.
        $this->elements = array_values($this->elements);
        $this->count = count($this->elements);
    }

    /**
     * This checks if the current list position is valid
     * @see \Iterator::valid()
     *
     * @access public
     *
     * @return boolean Is the current list position valid?
     */
    public function valid() {
        return isset($this->elements[$this->position]);
    }

    /**
     * This returns $this->metadata via __get()
     *
     * @access protected
     *
     * @return array The list's metadata
     */
    protected function _getMetadata() {
        return $this->metadata;
    }

    /**
     * This sets $this->metadata via __set()
     *
     * @access protected
     *
     * @param array $metadata: Array of new metadata
     *
     * @return void
     */
    protected function _setMetadata(array $metadata = []) {
        $this->metadata = $metadata;
    }

    /**
     * This is the constructor
     *
     * @access public
     *
     * @param array $elements: Array of documents initially setting up the list
     * @param array $metadata: Array of initial metadata
     *
     * @return void
     */
    public function __construct(array $elements = [], array $metadata = []) {
        if (empty($elements)
            && empty($metadata)) {
            // Let's check the user's session.
            $sessionData = Helper::loadFromSession(get_class($this));
            // Restore list from session data.
            if (is_array($sessionData)) {
                if (is_array($sessionData[0])) {
                    $this->elements = $sessionData[0];
                }
                if (is_array($sessionData[1])) {
                    $this->metadata = $sessionData[1];
                }
            }
        } else {
            // Add metadata to the list.
            $this->metadata = $metadata;
            // Add initial set of elements to the list.
            $this->elements = $elements;
        }
        $this->count = count($this->elements);
    }

    /**
     * This magic method is invoked each time a clone is called on the object variable
     * (This method is defined as private/protected because singleton objects should not be cloned)
     *
     * @access protected
     *
     * @return void
     */
    protected function __clone()
    {}

    /**
     * This magic method is called each time an invisible property is referenced from the object
     *
     * @access public
     *
     * @param string $var: Name of variable to get
     *
     * @return mixed Value of $this->$var
     */
    public function __get($var) {
        $method = '_get'.ucfirst($var);
        if (!property_exists($this, $var)
            || !method_exists($this, $method)) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->__get('.$var.')] There is no getter function for property "'.$var.'"', $this->extKey, SYSLOG_SEVERITY_WARNING);
            }
            return;
        } else {
            return $this->$method();
        }
    }

    /**
     * This magic method is called each time an invisible property is referenced from the object
     *
     * @access public
     *
     * @param string $var: Name of variable to set
     * @param mixed $value: New value of variable
     *
     * @return void
     */
    public function __set($var, $value) {
        $method = '_set'.ucfirst($var);
        if (!property_exists($this, $var)
            || !method_exists($this, $method)) {
            if (TYPO3_DLOG) {
                \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('[\Kitodo\Dlf\Common\DocumentList->__set('.$var.', [data])] There is no setter function for property "'.$var.'"', $this->extKey, SYSLOG_SEVERITY_WARNING, $value);
            }
        } else {
            $this->$method($value);
        }
    }

    /**
     * This magic method is executed prior to any serialization of the object
     * @see __wakeup()
     *
     * @access public
     *
     * @return array Properties to be serialized
     */
    public function __sleep() {
        return ['elements', 'metadata'];
    }

    /**
     * This magic method is executed after the object is deserialized
     * @see __sleep()
     *
     * @access public
     *
     * @return void
     */
    public function __wakeup() {
        $this->count = count($this->elements);
    }
}
