<?php
namespace Causal\Sphinx\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * ReStructuredText Editor for the 'sphinx' extension.
 *
 * @category    Backend Module
 * @package     TYPO3
 * @subpackage  tx_sphinx
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class RestEditorController extends AbstractActionController {

	// -----------------------------------------------
	// STANDARD ACTIONS
	// -----------------------------------------------

	/**
	 * Edit action.
	 *
	 * @param string $reference Reference of a documentation
	 * @param string $document The document
	 * @return void
	 * @throws \RuntimeException
	 */
	protected function editAction($reference, $document) {
		$parts = $this->parseReferenceDocument($reference, $document);
		$contents = file_get_contents($parts['filename']);

		$this->view->assign('reference', $reference);
		$this->view->assign('extensionKey', $parts['extensionKey']);
		$this->view->assign('document', $document);
		$this->view->assign('contents', $contents);
		$this->view->assign('filename', $parts['filename']);

		$buttons = $this->getButtons();
		$this->view->assign('buttons', $buttons);

		$this->view->assign('controller', $this);
	}

	// -----------------------------------------------
	// AJAX ACTIONS
	// -----------------------------------------------

	/**
	 * Saves the contents and recompiles the whole documentation if needed.
	 *
	 * @param string $reference Reference of a documentation
	 * @param string $document The document
	 * @param string $contents New contents to be saved
	 * @param boolean $compile
	 * @return void
	 */
	protected function saveAction($reference, $document, $contents, $compile = FALSE) {
		$response = array();
		try {
			$parts = $this->parseReferenceDocument($reference, $document);

			$success = \TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($parts['filename'], $contents);
			if (!$success) {
				throw new \RuntimeException(sprintf(
					$this->translate('editor.message.save.failure'),
					$parts['filename']
				), 1370011487);
			}

			if ($compile) {
				$layout = 'json';
				$force = TRUE;
				$outputFilename = NULL;

				switch ($parts['type']) {
					case 'EXT':
						$outputFilename = \Causal\Sphinx\Utility\GeneralUtility::generateDocumentation($parts['extensionKey'], $layout, $force, $parts['locale']);
						break;
					case 'USER':
						$outputFilename = NULL;
						$this->signalSlotDispatcher->dispatch(
							'Causal\\Sphinx\\Controller\\DocumentationController',
							'renderUserDocumentation',
							array(
								'identifier' => $parts['identifier'],
								'layout' => $layout,
								'force' => $force,
								'documentationUrl' => &$outputFilename,
							)
						);
						break;
				}
				if (substr($outputFilename, -4) === '.log') {
					throw new \RuntimeException($this->translate('editor.message.compile.failure'), 1370011537);
				}
			}

			$response['status'] = 'success';
		} catch (\RuntimeException $e) {
			$response['status'] = 'error';
			$response['statusText'] = $e->getMessage();
		}

		header('Content-type: application/json');
		echo json_encode($response);
		exit;
	}

	/**
	 * Autocomplete action to retrieve an documentation key.
	 *
	 * @return void
	 */
	protected function autocompleteAction() {
		// no term passed - just exit early with no response
		if (empty($_GET['term'])) exit;
		$q = strtolower($_GET['term']);

		$extensionTable = 'tx_extensionmanager_domain_model_extension';
		$items = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'uid, extension_key, title',
			$extensionTable,
			'last_updated>1370296800 AND ' .	// After 04.06.2013
				$GLOBALS['TYPO3_DB']->searchQuery(
					array($q),
					array('extension_key', 'title', 'description'),
					$extensionTable
				),
			'',
			'last_updated DESC',
			15
		);

		$result = array();
		foreach ($items as $item) {
			$reference = 'EXT:' . $item['extension_key'];
			if (isset($result[$reference])) continue;
			$result[$reference] = array(
				'id' => 'http://docs.typo3.org/typo3cms/extensions/' . $item['extension_key'],
				'label' => $item['title'] . ' (' . $item['extension_key'] . ')',
				'value' => $reference,
			);
		}

		// Official documents
		// See \TYPO3\CMS\Documentation\Service\DocumentationService::getOfficialDocuments()
		$cacheFile = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(
			'typo3temp/documents.json'
		);
		if (!is_file($cacheFile) || filemtime($cacheFile) < time() - 86400) {
			$json = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl('http://docs.typo3.org/typo3cms/documents.json');
			if ($json) {
				\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($cacheFile, $json);
			}
		}
		if (is_file($cacheFile)) {
			$documents = json_decode(file_get_contents($cacheFile), TRUE);
			foreach ($documents as $document) {
				if (stripos($document['shortcut'] . ' ' . $document['title'], $q) !== FALSE) {
					$result[] = array(
						'id' => $document['url'],
						'label' => $document['title'],
						'value' => $document['key'],
					);
				}
			}
		}

		header('Content-type: application/json');
		echo json_encode(array_values($result));
		exit;
	}

	/**
	 * Returns the references from the objects.inv index of a given
	 * extension.
	 *
	 * @param string $reference
	 * @param string $remoteUrl
	 * @param boolean $usePrefix
	 * @param boolean $json
	 * @return void|string
	 */
	public function accordionReferencesAction($reference, $remoteUrl = '', $usePrefix = TRUE, $json = TRUE) {
		if (substr($reference, 0, 4) === 'EXT:') {
			list($prefix, $locale) = explode('.', substr($reference, 4));
			$reference = $prefix;
		} else {
			$locale = '';
			// Use last segment of reference as prefix
			$segments = explode('.', $reference);
			$prefix = end($segments);
		}
		$references = \Causal\Sphinx\Utility\GeneralUtility::getIntersphinxReferences($reference, $locale, $remoteUrl);
		$out = array();

		$lastMainChapter = '';
		foreach ($references as $chapter => $refs) {
			if (is_numeric($chapter)
				|| $chapter === 'genindex'    || $chapter === 'genindex.htm'
				|| $chapter === 'py-modindex' || $chapter === 'py-modindex.htm'
				|| $chapter === 'search'      || $chapter === 'search.htm') {

				continue;
			}

			list($mainChapter, $_) = explode('/', $chapter, 2);
			if ($mainChapter !== $lastMainChapter) {
				if ($lastMainChapter !== '') {
					$out[] = '</div>';	// End of accordion content panel
				}

				// UpperCamelCase to separate words
				$titleMainChapter = implode(' ', preg_split('/(?=[A-Z])/', $mainChapter));

				$out[] = '<h3><a href="#"'.
						' title="' . htmlspecialchars(sprintf(
						$this->translate('editor.tooltip.references.chapter'), $titleMainChapter))
					. '">' . htmlspecialchars($titleMainChapter) . '</a></h3>';
				$out[] = '<div>';	// Start of accordion content panel
			}

			$out[] = '<h4>' . htmlspecialchars(substr($chapter, strlen($mainChapter))) . '</h4>';
			$out[] = '<ul>';
			foreach ($refs as $ref) {
				$restReference = ':ref:`' . ($usePrefix ? $prefix . ':' : '') . $ref['name'] . '` ';
				$arg1 = '\'' . str_replace(array('\'', '"'), array('\\\'', '\\"'), $restReference) . '\'';
				$arg2 = '\'' . ($usePrefix ? $prefix : '') . '\'';
				$arg3 = '\'' . $remoteUrl . '\'';
				$insertJS = 'EditorInsert(' . $arg1 . ',' . $arg2 . ',' . $arg3 . ');';
				$out[] = '<li><a href="#" title="' . htmlspecialchars($this->translate('editor.tooltip.references.insert')) .
				 '" onclick="' . $insertJS . '">' . htmlspecialchars($ref['title']) . '</a></li>';
			}
			$out[] = '</ul>';

			$lastMainChapter = $mainChapter;
		}
		$out[] = '</div>';	// End of accordion content panel
		$html = implode(LF, $out);

		if (!$json) {
			return $html;
		}

		header('Content-type: application/json');
		echo json_encode(array('html' => $html));
		exit;
	}

	/**
	 * Updates Intersphinx mapping by adding a reference to the
	 * documentation of $extensionKey.
	 *
	 * @param string $reference Reference of a documentation
	 * @param string $prefix
	 * @param string $remoteUrl
	 * @return void
	 * @throws \RuntimeException
	 */
	protected function updateIntersphinxAction($reference, $prefix, $remoteUrl = '') {
		if (substr($reference, 0, 4) !== 'EXT:') {
			throw new \RuntimeException('Sorry this action currently only supports extension references', 1378419136);
		}
		list($documentationExtension, $locale) = explode('.', substr($reference, 4));
		$settingsFilename = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($documentationExtension) .
			'Documentation/' . ($locale ? 'Localization.' . $locale . '/' : '') . 'Settings.yml';

		\Causal\Sphinx\Utility\GeneralUtility::addIntersphinxMapping(
			$settingsFilename,
			$prefix,
			$remoteUrl ?: 'http://docs.typo3.org/typo3cms/extensions/' . $prefix
		);

		exit;
	}

	// -----------------------------------------------
	// INTERNAL METHODS
	// -----------------------------------------------

	/**
	 * Parses a reference and a document and returns the corresponding filename,
	 * the type of reference, its identifier, the extension key (if available)
	 * and the locale (if available).
	 *
	 * @param string $reference
	 * @param string $document
	 * @return array
	 * @throws \RuntimeException
	 */
	protected function parseReferenceDocument($reference, $document) {
		$extensionKey = NULL;
		$locale = NULL;

		list($type, $identifier) = explode(':', $reference, 2);
		switch ($type) {
			case 'EXT':
				list($extensionKey, $locale) = explode('.', $identifier, 2);
				$filename = $this->getFilename($extensionKey, $document, $locale);
				break;
			case 'USER':
				$filename = NULL;
				$this->signalSlotDispatcher->dispatch(
					__CLASS__,
					'retrieveRestFilename',
					array(
						'identifier' => $identifier,
						'document' => $document,
						'filename' => &$filename,
					)
				);
				if ($filename === NULL) {
					throw new \RuntimeException('No slot found to retrieve filename with identifier "' . $identifier . '"', 1371418203);
				}
				break;
			default:
				throw new \RuntimeException('Unknown reference "' . $reference . '"', 1371163472);
		}

		return array(
			'filename'     => $filename,
			'type'         => $type,
			'identifier'   => $identifier,
			'extensionKey' => $extensionKey,
			'locale'       => $locale
		);
	}

	/**
	 * Returns the ReST filename corresponding to a given document.
	 *
	 * @param string $extensionKey The TYPO3 extension key
	 * @param string $document The document
	 * @param string $locale The locale to use
	 * @return string
	 * @throws \RuntimeException
	 */
	protected function getFilename($extensionKey, $document, $locale) {
		if (empty($locale)) {
			$documentationType = \Causal\Sphinx\Utility\GeneralUtility::getDocumentationType($extensionKey);
		} else {
			$documentationType = \Causal\Sphinx\Utility\GeneralUtility::getLocalizedDocumentationType($extensionKey, $locale);
		}
		switch ($documentationType) {
			case \Causal\Sphinx\Utility\GeneralUtility::DOCUMENTATION_TYPE_SPHINX:
				$path = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($extensionKey);
				if (empty($locale)) {
					$path .= 'Documentation/';
				} else {
					$localizationDirectories = \Causal\Sphinx\Utility\GeneralUtility::getLocalizationDirectories($extensionKey);
					$path .= $localizationDirectories[$locale]['directory'] . '/';
				}
				$filename = $path . ($document ? substr($document, 0, -1) : 'Index') . '.rst';
				break;
			case \Causal\Sphinx\Utility\GeneralUtility::DOCUMENTATION_TYPE_README:
				$path = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($extensionKey);
				$filename = $path . 'README.rst';
				break;
			default:
				throw new \RuntimeException('Unsupported documentation type for extension "' . $extensionKey . '"', 1371117564);
		}

		// Security check
		$path = realpath($path);
		$filename = realpath($filename);
		if (substr($filename, 0, strlen($path)) !== $path) {
			throw new \RuntimeException('Security notice: attempted to access a file outside of extension "' . $extensionKey . '"', 1370011326);
		}

		return $filename;
	}

	/**
	 * Returns the toolbar buttons.
	 *
	 * @return string
	 */
	protected function getButtons() {
		$buttons = array();

		$buttons[] = $this->createToolbarButton(
			'#',
			$this->translate('toolbar.editor.close'),
			't3-icon-actions-document t3-icon-document-close',
			'getContentIframe().closeEditor()'
		);
		$buttons[] = '&nbsp;';

		$buttons[] = $this->createToolbarButton(
			'#',
			$this->translate('toolbar.editor.save'),
			't3-icon-actions-document t3-icon-document-save',
			'getContentIframe().save()'
		);
		$buttons[] = $this->createToolbarButton(
			'#',
			$this->translate('toolbar.editor.saveclose'),
			't3-icon-actions-document t3-icon-document-save-close',
			'getContentIframe().saveAndClose()'
		);

		$buttons[] = '<div style="float:right">';
		$buttons[] = '<input type="checkbox" id="tx-sphinx-showinvisibles" onclick="getContentIframe().editor.setShowInvisibles(this.checked)" value="1" />' .
			'<label for="tx-sphinx-showinvisibles">' .
			$this->translate('toolbar.editor.showInvisibles') . '</label>';
		$buttons[] = '</div>';

		return implode(' ', $buttons);
	}

}

?>