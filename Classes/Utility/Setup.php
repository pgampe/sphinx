<?php
namespace Causal\Sphinx\Utility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013-2014 Xavier Perseguers <xavier@causal.ch>
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

use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use Causal\Sphinx\Utility\GitUtility;

/**
 * Sphinx environment setup.
 *
 * @category    Utility
 * @package     TYPO3
 * @subpackage  tx_sphinx
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class Setup {

	/** @var string */
	static protected $extKey = 'sphinx';

	/** @var array */
	static protected $log = array();

	/**
	 * Returns the version of python.
	 *
	 * @return string The version of python
	 */
	static public function getPythonVersion() {
		$version = NULL;
		if (CommandUtility::checkCommand('python')) {
			$python = escapeshellarg(CommandUtility::getCommand('python'));
			$cmd = $python . ' -V 2>&1';
			static::exec($cmd, $out, $ret);
			if ($ret === 0) {
				$versionLine = array_shift($out);
				if (preg_match('/Python ([0-9.]+)/', $versionLine, $matches)) {
					$version = $matches[1];
				}
			}
		}
		return $version;
	}

	/**
	 * Initializes the environment by creating directories to hold sphinx and 3rd
	 * party tools.
	 *
	 * @return array Error messages, if any
	 */
	static public function createLibraryDirectories() {
		$errors = array();

		if ($GLOBALS['TYPO3_CONF_VARS']['BE']['disable_exec_function'] == 1) {
			$errors[] = 'You have disabled exec() with $TYPO3_CONF_VARS[\'BE\'][\'disable_exec_function\'] = \'1\'. ' .
				'Please open System > Install > All configuration and set it to 0 to proceed.';
			return $errors;
		}

		if (!CommandUtility::checkCommand('python')) {
			$errors[] = 'Python interpreter was not found. Hint: You probably should double-check '.
				'$TYPO3_CONF_VARS[\'SYS\'][\'binPath\'] and/or $TYPO3_CONF_VARS[\'SYS\'][\'binSetup\'].';
		}
		if (!CommandUtility::checkCommand('unzip')) {
			$errors[] = 'Unzip cannot be executed. Hint: You probably should double-check '.
				'$TYPO3_CONF_VARS[\'SYS\'][\'binPath\'] and/or $TYPO3_CONF_VARS[\'SYS\'][\'binSetup\'].';
		}

		$directories = array(
			'typo3temp/tx_sphinx/sphinx-doc/',
			'typo3temp/tx_sphinx/sphinx-doc/bin/',
			'uploads/tx_sphinx/',
		);
		foreach ($directories as $directory) {
			$absoluteDirectory = GeneralUtility::getFileAbsFileName($directory);
			if (!is_dir($absoluteDirectory)) {
				GeneralUtility::mkdir_deep($absoluteDirectory);
			}
			if (is_dir($absoluteDirectory)) {
				if (!is_writable($absoluteDirectory)) {
					$errors[] = 'Directory ' . $absoluteDirectory . ' is read-only.';
				}
			} else {
				$errors[] = 'Cannot create directory ' . $absoluteDirectory . '.';
			}
		}

		return $errors;
	}

	/**
	 * Returns TRUE if the source files of Sphinx are available locally.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @return boolean
	 */
	static public function hasSphinxSources($version) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . $version . '/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Sphinx.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @param string $url Complete URL of the zip file containing the sphinx sources
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see https://bitbucket.org/birkenfeld/sphinx/
	 */
	static public function downloadSphinxSources($version, $url, array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		$zipFilename = $tempPath . $version . '.zip';
		static::$log[] = '[INFO] Fetching ' . $url;
		$zipContent = MiscUtility::getUrl($url);
		if ($zipContent && GeneralUtility::writeFile($zipFilename, $zipContent)) {
			$output[] = '[INFO] Sphinx ' . $version . ' has been downloaded.';
			$targetPath = $sphinxSourcesPath . $version;

			// Unzip the Sphinx archive
			$out = array();
			if (static::unarchive($zipFilename, $targetPath, 'birkenfeld-sphinx-')) {
				$output[] = '[INFO] Sphinx ' . $version . ' has been unpacked.';

				// Patch Sphinx to let us get colored output
				$sourceFilename = $targetPath . '/sphinx/util/console.py';

				// Compatibility with Windows platform
				$sourceFilename = str_replace('/', DIRECTORY_SEPARATOR, $sourceFilename);

				if (file_exists($sourceFilename)) {
					static::$log[] = '[INFO] Patching file ' . $sourceFilename;
					$contents = file_get_contents($sourceFilename);
					$contents = str_replace(
						'def color_terminal():',
						"def color_terminal():\n    if 'COLORTERM' in os.environ:\n        return True",
						$contents
					);
					GeneralUtility::writeFile($sourceFilename, $contents);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not extract Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Cannot fetch file ' . $url . '.';
		}

		return $success;
	}

	/**
	 * Builds and installs Sphinx locally.
	 *
	 * @param string $version Version name (e.g., 1.0.0)
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildSphinx($version, array &$output = NULL) {
		$success = TRUE;
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		// Sphinx 1.2 requires Python 2.5
		// http://forge.typo3.org/issues/53246
		if (version_compare($version, '1.1.99', '>')) {
			$pythonVersion = static::getPythonVersion();
			if (version_compare($pythonVersion, '2.5', '<')) {
				$success = FALSE;
				$output[] = '[ERROR] Could not install Sphinx ' . $version . ': You are using Python ' . $pythonVersion .
					' but the required version is at least 2.5.';
				return $success;
			}
		}

		$pythonHome = NULL;
		$pythonLib = NULL;
		$setupFile = $sphinxSourcesPath . $version . DIRECTORY_SEPARATOR . 'setup.py';

		if (is_file($setupFile)) {
			$python = escapeshellarg(CommandUtility::getCommand('python'));
			$cmd = 'cd ' . escapeshellarg(PathUtility::dirname($setupFile)) . ' && ' .
				$python . ' setup.py clean 2>&1 && ' .
				$python . ' setup.py build 2>&1';
			$out = array();
			static::exec($cmd, $out, $ret);
			if ($ret === 0) {
				$pythonHome = $sphinxPath . $version;
				$pythonLib = $pythonHome . '/lib/python';

				// Compatibility with Windows platform
				$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

				static::$log[] = '[INFO] Recreating directory ' . $pythonHome;
				GeneralUtility::rmdir($pythonHome, TRUE);
				GeneralUtility::mkdir_deep($pythonLib . DIRECTORY_SEPARATOR);

				$cmd = 'cd ' . escapeshellarg(PathUtility::dirname($setupFile)) . ' && ' .
					MiscUtility::getExportCommand('PYTHONPATH', $pythonLib) . ' && ' .
					$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
				$out = array();
				static::exec($cmd, $out, $ret);
				if ($ret === 0) {
					$output[] = '[OK] Sphinx ' . $version . ' has been successfully installed.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not install Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not build Sphinx ' . $version . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		if ($success) {
			$shortcutScripts = array(
				'sphinx-build',
				'sphinx-quickstart',
			);
			$pythonPath = $sphinxPath . $version . '/lib/python';

			// Compatibility with Windows platform
			$pythonPath = str_replace('/', DIRECTORY_SEPARATOR, $pythonPath);

			foreach ($shortcutScripts as $shortcutScript) {
				$shortcutFilename = $sphinxPath . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript . '-' . $version;
				$scriptFilename = $sphinxPath . $version . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript;

				if (TYPO3_OS === 'WIN') {
					$shortcutFilename .= '.bat';
					$scriptFilename .= '.exe';

					$script = <<<EOT
@ECHO OFF
SET PYTHONPATH=$pythonPath

$scriptFilename %*
EOT;
					// Use CRLF under Windows
					$script = str_replace(CR, LF, $script);
					$script = str_replace(LF, CR . LF, $script);
				} else {
					$script = <<<EOT
#!/bin/bash

export PYTHONPATH=$pythonPath

$scriptFilename "\$@"
EOT;
				}

				GeneralUtility::writeFile($shortcutFilename, $script);
				chmod($shortcutFilename, 0755);
			}
		}

		return $success;
	}

	/**
	 * Removes a local version of Sphinx (sources + build).
	 *
	 * @param string $version Version name (e.g., "1.0.0")
	 * @param NULL|array $output Log of operations
	 * @return void
	 */
	static public function removeSphinx($version, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		if (is_dir($sphinxSourcesPath . $version)) {
			if (GeneralUtility::rmdir($sphinxSourcesPath . $version, TRUE)) {
				$output[] = '[OK] Sources of Sphinx ' . $version . ' have been deleted.';
			} else {
				$output[] = '[ERROR] Could not delete sources of Sphinx ' . $version . '.';
			}
		}
		if (is_dir($sphinxPath . $version)) {
			if (GeneralUtility::rmdir($sphinxPath . $version, TRUE)) {
				$output[] = '[OK] Sphinx ' . $version . ' has been deleted.';
			} else {
				$output[] = '[ERROR] Could not delete Sphinx ' . $version . '.';
			}
		}

		$shortcutScripts = array(
			'sphinx-build-' . $version,
			'sphinx-quickstart-' . $version,
		);
		foreach ($shortcutScripts as $shortcutScript) {
			$shortcutFilename = $sphinxPath . 'bin' . DIRECTORY_SEPARATOR . $shortcutScript;

			if (TYPO3_OS === 'WIN') {
				$shortcutFilename .= '.bat';
			}

			if (is_file($shortcutFilename)) {
				@unlink($shortcutFilename);
			}
		}
	}

	/**
	 * Returns TRUE if the source files of the TYPO3 ReST tools are available locally.
	 *
	 * @return boolean
	 */
	static public function hasRestTools() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of the TYPO3 ReST tools.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://forge.typo3.org/projects/tools-rest
	 */
	static public function downloadRestTools(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		// Try to clone from Git before falling back to downloading a snapshot
		if (GitUtility::isAvailable()) {
			$url = 'git://git.typo3.org/Documentation/RestTools.git';
			static::$log[] = '[INFO] Cloning ' . $url;
			if (GitUtility::cloneRepository($url, $sphinxSourcesPath)) {
				$output[] = '[INFO] TYPO3 ReStructuredText Tools have been cloned.';
				return $success;
			} else {
				$output[] = '[WARNING] Failed to clone TYPO3 ReStructured Text Tools, will use a snapshot.';
				if (is_dir($sphinxSourcesPath . 'RestTools')) {
					GeneralUtility::rmdir($sphinxSourcesPath . 'RestTools', TRUE);
				}
			}
		}

		$url = 'https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3';
		static::$log[] = '[INFO] Fetching ' . $url;
		$body = MiscUtility::getUrl($url);
		if (preg_match('#<a .*?href="/Documentation/RestTools\.git/snapshot/([0-9a-f]+)\.tar\.gz">tar\.gz</a>#', $body, $matches)) {
			$commit = $matches[1];
			$url = 'https://git.typo3.org/Documentation/RestTools.git/snapshot/' . $commit . '.tar.gz';
			$archiveFilename = $tempPath . 'RestTools.tar.gz';
			static::$log[] = '[INFO] Fetching ' . $url;
			$archiveContent = MiscUtility::getUrl($url);
			if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$output[] = '[INFO] TYPO3 ReStructuredText Tools (' . $commit . ') have been downloaded.';

				// Target path is compatible with directory structure of complete git project
				// allowing people to use the official git repository instead, if wanted
				$targetPath = $sphinxSourcesPath . 'RestTools' . DIRECTORY_SEPARATOR . 'ExtendingSphinxForTYPO3';

				// Unpack TYPO3 ReST Tools archive
				$out = array();
				if (static::unarchive($archiveFilename, $targetPath, 'RestTools-' . substr($commit, 0, 7), $out)) {
					$output[] = '[INFO] TYPO3 ReStructuredText Tools have been unpacked.';
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not extract TYPO3 ReStructuredText Tools:' . LF . LF . implode($out, LF);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not download ' . htmlspecialchars('https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3');
		}

		return $success;
	}

	/**
	 * Builds and installs TYPO3 ReST tools locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build the ReST tools for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildRestTools($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		// Patch RestTools to support rst2pdf. We do it here and not after downloading
		// to let user build RestTools with Git repository as well
		// @see http://forge.typo3.org/issues/49341
		$globalSettingsFilename = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/src/t3sphinx/settings/GlobalSettings.yml';

		// Compatibility with Windows platform
		$globalSettingsFilename = str_replace('/', DIRECTORY_SEPARATOR, $globalSettingsFilename);

		$configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][static::$extKey]);
		$installRst2Pdf = TYPO3_OS !== 'WIN' && $configuration['install_rst2pdf'] === '1';
		$isPatched = !$installRst2Pdf;

		if ($installRst2Pdf && static::hasLibrary('rst2pdf', $sphinxVersion)) {
			if (is_file($globalSettingsFilename)) {
				$globalSettings = file_get_contents($globalSettingsFilename);
				$rst2pdfLibrary = 'rst2pdf.pdfbuilder';
				$isPatched = strpos($globalSettings, '- ' . $rst2pdfLibrary) !== FALSE;

				if (!$isPatched && is_writable($globalSettingsFilename)) {
					if (strpos($globalSettings, '- ' . $rst2pdfLibrary) === FALSE) {
						$globalSettingsLines = explode(LF, $globalSettings);
						$buffer = array();
						$numberOfLines = count($globalSettingsLines);
						for ($i = 0; $i < $numberOfLines; $i++) {
							if (trim($globalSettingsLines[$i]) === 'extensions:') {
								while (!empty($globalSettingsLines[$i])) {
									$buffer[] = $globalSettingsLines[$i];
									$i++;
								};
								$buffer[] = '  - ' . $rst2pdfLibrary;
							}
							$buffer[] = $globalSettingsLines[$i];
						}
						$isPatched = GeneralUtility::writeFile($globalSettingsFilename, implode(LF, $buffer));
					}
				}
			} else {
				// Should not happen
				$output[] = '[ERROR] Could not find file "' . $globalSettingsFilename . '".';
			}
		}

		if (!$isPatched) {
			$output[] = '[WARNING] Could not patch file "' . $globalSettingsFilename .
				'". Please check file permissions. rst2pdf may fail to run properly with error message "Builder name pdf not registered".';
		}

		$setupFile = $sphinxSourcesPath . 'RestTools/ExtendingSphinxForTYPO3/setup.py';

		// Compatibility with Windows platform
		$setupFile = str_replace('/', DIRECTORY_SEPARATOR, $setupFile);

		if (is_file($setupFile)) {
			$success = static::buildWithPython(
				'TYPO3 RestructuredText Tools',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of 3rd-party libraries are available locally.
	 *
	 * @return boolean
	 */
	static public function hasThirdPartyLibraries() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'sphinx-contrib/make-ext.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of 3rd-party libraries.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see https://bitbucket.org/xperseguers/sphinx-contrib/
	 */
	static public function downloadThirdPartyLibraries(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		if (!CommandUtility::checkCommand('unzip')) {
			$success = FALSE;
			$output[] = '[WARNING] Could not find command unzip. 3rd-party libraries were not installed.';
		} else {
			$url = 'https://bitbucket.org/xperseguers/sphinx-contrib/overview';
			$content = MiscUtility::getUrl($url);
			$content = substr($content, strpos($content, '<dl class="metadata">'));
			// Search for the download link
			// <a rel="nofollow"
			// 			href="/xperseguers/sphinx-contrib/get/a3d904f8ab24.zip"
			//		>(download)</a>
			if (preg_match('#href="(/xperseguers/sphinx-contrib/get/[0-9a-f]+\.zip)"#', $content, $matches)) {
				$url = 'https://bitbucket.org' . $matches[1];
				$archiveFilename = $tempPath . 'sphinx-contrib.zip';
				$archiveContent = MiscUtility::getUrl($url);
				if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
					$output[] = '[INFO] 3rd-party libraries for Sphinx have been downloaded.';

					$targetPath = $sphinxSourcesPath . 'sphinx-contrib';

					// Unpack 3rd-party libraries archive
					$out = array();
					if (static::unarchive($archiveFilename, $targetPath, 'xperseguers-sphinx-contrib-', $out)) {
						$output[] = '[INFO] 3rd-party libraries for Sphinx have been unpacked.';
					} else {
						$success = FALSE;
						$output[] = '[ERROR] Could not extract 3rd-party libraries for Sphinx:' . LF . LF . implode($out, LF);
					}
				} else {
					$success = FALSE;
					$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
				}
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not fetch ' . htmlspecialchars($url);
			}
		}

		return $success;
	}

	/**
	 * Builds and installs 3rd-party libraries locally.
	 *
	 * @param string $plugin The 3rd-party plugin to build
	 * @param string $sphinxVersion The Sphinx version to build 3rd-party libraries for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildThirdPartyLibraries($plugin, $sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'sphinx-contrib' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = static::buildWithPython(
				'3rd-party extension "sphinxcontrib.' . $plugin . '"',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns a list of available 3rd-party plugins.
	 *
	 * @return array
	 */
	static public function getAvailableThirdPartyPlugins() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$pluginsPath = $sphinxSourcesPath . 'sphinx-contrib/';
		$plugins = array();

		$descriptions = array(
			'aafig' => 'render embeded ASCII art as nice images using aafigure.',
			'actdiag' => 'embed activity diagrams by using actdiag',
			'adadomain' => 'an extension for Ada support (Sphinx 1.0 needed)',
			'ansi' => 'parse ANSI color sequences inside documents',
			'autoprogram' => 'documenting CLI programs',
			'autorun' => 'Execute code in a runblock directive.',
			'blockdiag' => 'embed block diagrams by using blockdiag',
			'cheeseshop' => 'easily link to PyPI packages',
			'clearquest' => 'create tables from ClearQuest queries.',
			'cmakedomain' => 'a domain for CMake',
			'coffeedomain' => 'a domain for (auto)documenting CoffeeScript source code.',
			'context' => 'a builder for ConTeXt.',
			'doxylink' => 'Link to external Doxygen-generated HTML documentation',
			'domaintools' => 'A tool for easy domain creation',
			'email' => 'obfuscate email addresses',
			'erlangdomain' => 'an extension for Erlang support (Sphinx 1.0 needed)',
			'exceltable' => 'embed Excel spreadsheets into documents using exceltable',
			'feed' => 'an extension for creating syndication feeds and time-based overviews from your site content',
			'findanything' => 'an extension to add Sublime Text 2 like findanything panel to your documentation to find pages, sections and index entries while typing',
			'gnuplot' => 'produces images using gnuplot language.',
			'googleanalytics' => 'track html visitors statistics',
			'googlechart' => 'embed charts by using Google Chart_',
			'googlemaps' => 'embed maps by using Google Maps_',
			'httpdomain' => 'a domain for documenting RESTful HTTP APIs.',
			'hyphenator' => 'client-side hyphenation of HTML using hyphenator',
			'inlinesyntaxhighlight' => 'inline syntax highlighting',
			'lassodomain' => 'a domain for documenting Lasso source code',
			'lilypond' => 'an extension inserting music scripts from Lilypond in PNG format.',
			'makedomain' => 'a domain for GNU Make',
			'matlabdomain' => 'document MATLAB and GNU Octave code.',
			'mockautodoc' => 'mock imports.',
			'mscgen' => 'embed mscgen-formatted MSC (Message Sequence Chart)s.',
			'napoleon' => 'supports Google style and NumPy style docstrings.',
			'nicoviceo' => 'embed videos from nicovideo',
			'numfig' => 'numbered figures',
			'nwdiag' => 'embed network diagrams by using nwdiag',
			'omegat' => 'support tools to collaborate with OmegaT (Sphinx 1.1 needed)',
			'osaka' => 'convert standard Japanese doc to Osaka dialect (it is joke extension)',
			'paverutils' => 'an alternate integration of Sphinx with Paver.',
			'phpdomain' => 'an extension for PHP support',
			'plantuml' => 'embed UML diagram by using PlantUML',
			'py_directive' => 'Execute python code in a py directive and return a math node.',
			'rawfiles' => 'copy raw files, like a CNAME.',
			'requirements' => 'declare requirements wherever you need (e.g. in test docstrings), mark statuses and collect them in a single list',
			'restbuilder' => 'a builder for reST (reStructuredText) files.',
			'rubydomain' => 'an extension for Ruby support (Sphinx 1.0 needed)',
			'sadisplay' => 'display SqlAlchemy model sadisplay',
			'sdedit' => 'an extension inserting sequence diagram by using Quick Sequence. Diagram Editor (sdedit)',
			'seqdiag' => 'embed sequence diagrams by using seqdiag',
			'slide' => 'embed presentation slides on slideshare and other sites.',
			'swf' => 'embed flash files',
			'sword' => 'an extension inserting Bible verses from Sword.',
			'tikz' => 'draw pictures with the TikZ/PGF LaTeX package.',
			'traclinks' => 'create TracLinks to a Trac instance from within Sphinx',
			'whooshindex' => 'whoosh indexer extension',
			'youtube' => 'embed videos from YouTube',
			'zopeext' => 'provide an autointerface directive for using Zope interfaces.',
		);

		// We have no official list but Xavier Perseguers (@xperseguers) takes care
		// of maintaining this list
		$availableOnDocsTypo3Org = array(
			'googlechart',
			'googlemaps',
			'httpdomain',
			'numfig',
			'slide',
			'youtube',
		);

		$directories = GeneralUtility::get_dirs($pluginsPath);
		if (is_array($directories)) {
			foreach ($directories as $directory) {
				if ($directory{0} === '_' || !is_file($pluginsPath . $directory . '/README.rst')) {
					continue;
				}
				$plugins[] = array(
					'name' => $directory,
					'description' => isset($descriptions[$directory]) ? $descriptions[$directory] : '',
					'readme' => substr($pluginsPath . $directory . '/README.rst', strlen(PATH_site) - 1),
					'docst3o' => in_array($directory, $availableOnDocsTypo3Org),
				);
			}
		}

		return $plugins;
	}

	/**
	 * Returns TRUE if the source files of PyYAML are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPyYaml() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'PyYAML/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of PyYAML.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://pyyaml.org/
	 */
	static public function downloadPyYaml(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		$url = 'http://pyyaml.org/download/pyyaml/PyYAML-3.10.tar.gz';
		$archiveFilename = $tempPath . 'PyYAML-3.10.tar.gz';
		$archiveContent = MiscUtility::getUrl($url);
		if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
			$output[] = '[INFO] PyYAML 3.10 has been downloaded.';

			$targetPath = $sphinxSourcesPath . 'PyYAML';

			// Unpack PyYAML archive
			$out = array();
			if (static::unarchive($archiveFilename, $targetPath, 'PyYAML-3.10', $out)) {
				$output[] = '[INFO] PyYAML has been unpacked.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not extract PyYAML:' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
		}

		return $success;
	}

	/**
	 * Builds and installs PyYAML locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build PyYAML for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPyYaml($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'PyYAML' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = static::buildWithPython(
				'PyYAML',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of Python Imaging Library are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPIL() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'Imaging/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Python Imaging Library.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see https://pypi.python.org/pypi/PIL
	 */
	static public function downloadPIL(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		$url = 'http://effbot.org/media/downloads/Imaging-1.1.7.tar.gz';
		$archiveFilename = $tempPath . 'Imaging-1.1.7.tar.gz';
		$archiveContent = MiscUtility::getUrl($url);
		if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
			$output[] = '[INFO] Python Imaging Library 1.1.7 has been downloaded.';

			$targetPath = $sphinxSourcesPath . 'Imaging';

			// Unpack Python Imaging Library archive
			$out = array();
			if (static::unarchive($archiveFilename, $targetPath, 'Imaging-1.1.7', $out)) {
				$output[] = '[INFO] Python Imaging Library has been unpacked.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Unknown structure in archive ' . $archiveFilename;
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
		}

		return $success;
	}

	/**
	 * Builds and installs Python Imaging Library locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build Python Imaging Library for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPIL($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'Imaging' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = static::buildWithPython(
				'Python Imaging Library',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if the source files of Pygments are available locally.
	 *
	 * @return boolean
	 */
	static public function hasPygments() {
		$sphinxSourcePath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcePath . 'Pygments/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of Pygments.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://pygments.org/
	 */
	static public function downloadPygments(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		$url = 'https://bitbucket.org/birkenfeld/pygments-main/get/1.6.tar.gz';
		$archiveFilename = $tempPath . 'pygments-1.6.tar.gz';
		$archiveContent = MiscUtility::getUrl($url);
		if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
			$output[] = '[INFO] Pygments 1.6 has been downloaded.';

			$targetPath = $sphinxSourcesPath . 'Pygments';

			// Unpack Pygments archive
			$out = array();
			if (static::unarchive($archiveFilename, $targetPath, 'birkenfeld-pygments-main-', $out)) {
				$output[] = '[INFO] Pygments has been unpacked.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Unknown structure in archive ' . $archiveFilename;
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
		}

		return $success;
	}

	/**
	 * Builds and installs Pygments locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build Pygments for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildPygments($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'Pygments' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			static::configureTyposcriptForPygments($output);

			$success = static::buildWithPython(
				'Pygments',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Configures TypoScript support for Pygments.
	 *
	 * @param NULL|array $output Log of operations
	 * @return void
	 */
	static private function configureTyposcriptForPygments(array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$lexersPath = $sphinxSourcesPath . 'Pygments' . DIRECTORY_SEPARATOR . 'pygments' . DIRECTORY_SEPARATOR . 'lexers' . DIRECTORY_SEPARATOR;

		$url = 'https://git.typo3.org/Documentation/RestTools.git/blob_plain/HEAD:/ExtendingPygmentsForTYPO3/_incoming/typoscript.py';
		$libraryFilename = $lexersPath . 'typoscript.py';
		$libraryContent = MiscUtility::getUrl($url);

		if ($libraryContent) {
			if (!is_file($libraryFilename) || md5_file($libraryFilename) !== md5($libraryContent)) {
				if (GeneralUtility::writeFile($libraryFilename, $libraryContent)) {
					$output[] = '[OK] TypoScript library for Pygments successfully downloaded/updated.';
				}
			}
			if (is_file($libraryFilename)) {
				// Update the list of Pygments lexers
				$python = escapeshellarg(CommandUtility::getCommand('python'));
				$cmd = 'cd ' . escapeshellarg($lexersPath) . ' && ' .
					$python . ' _mapping.py 2>&1';
				$out = array();
				static::exec($cmd, $out, $ret);
				if ($ret === 0) {
					$output[] = '[OK] TypoScript library successfully registered with Pygments.';
				} else {
					$output[] = '[WARNING] Could not install TypoScript library for Pygments.';
				}
			}
		}
	}

	/**
	 * Returns TRUE if the source files of rst2pdf are available locally.
	 *
	 * @return boolean
	 */
	static public function hasRst2Pdf() {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$setupFile = $sphinxSourcesPath . 'rst2pdf/setup.py';
		return is_file($setupFile);
	}

	/**
	 * Downloads the source files of rst2pdf.
	 *
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 * @see http://rst2pdf.ralsina.com.ar/
	 */
	static public function downloadRst2Pdf(array &$output = NULL) {
		$success = TRUE;
		$tempPath = static::getTemporaryPath();
		$sphinxSourcesPath = static::getSphinxSourcesPath();

		$url = 'http://rst2pdf.googlecode.com/files/rst2pdf-0.93.tar.gz';
		$archiveFilename = $tempPath . 'rst2pdf-0.93.tar.gz';
		$archiveContent = MiscUtility::getUrl($url);
		if ($archiveContent && GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
			$output[] = '[INFO] rst2pdf 0.93 has been downloaded.';

			$targetPath = $sphinxSourcesPath . 'rst2pdf';

			// Unpack rst2pdf archive
			$out = array();
			if (static::unarchive($archiveFilename, $targetPath, 'rst2pdf-0.93', $out)) {
				$output[] = '[INFO] rst2pdf has been unpacked.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not extract rst2pdf:' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not download ' . htmlspecialchars($url);
		}

		return $success;
	}

	/**
	 * Builds and installs rst2pdf locally.
	 *
	 * @param string $sphinxVersion The Sphinx version to build rst2pdf for
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 * @throws \Exception
	 */
	static public function buildRst2Pdf($sphinxVersion, array &$output = NULL) {
		$sphinxSourcesPath = static::getSphinxSourcesPath();
		$sphinxPath = static::getSphinxPath();

		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		// Compatibility with Windows platform
		$pythonHome = str_replace('/', DIRECTORY_SEPARATOR, $pythonHome);
		$pythonLib = str_replace('/', DIRECTORY_SEPARATOR, $pythonLib);

		if (!is_dir($pythonLib)) {
			$success = FALSE;
			$output[] = '[ERROR] Invalid Python library: ' . $pythonLib;
			return $success;
		}

		$setupFile = $sphinxSourcesPath . 'rst2pdf' . DIRECTORY_SEPARATOR . 'setup.py';
		if (is_file($setupFile)) {
			$success = static::buildWithPython(
				'rst2pdf',
				$setupFile,
				$pythonHome,
				$pythonLib,
				$output
			);
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Setup file ' . $setupFile . ' was not found.';
		}

		return $success;
	}

	/**
	 * Returns TRUE if a given Python library is present (installed).
	 *
	 * @param string $library Name of the library (without version)
	 * @param string $sphinxVersion The Sphinx version to check for
	 * @return boolean
	 */
	static public function hasLibrary($library, $sphinxVersion) {
		$sphinxPath = static::getSphinxPath();
		$pythonHome = $sphinxPath . $sphinxVersion;
		$pythonLib = $pythonHome . '/lib/python';

		$directories = GeneralUtility::get_dirs($pythonLib);
		foreach ($directories as $directory) {
			if (GeneralUtility::isFirstPartOfStr($directory, $library . '-')) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Returns a list of online available versions of Sphinx.
	 * Please note: all versions older than 1.0 are automatically discarded
	 * as they are most probably of absolutely no use.
	 *
	 * @return array
	 */
	static public function getSphinxAvailableVersions() {
		$sphinxUrl = 'https://bitbucket.org/birkenfeld/sphinx/downloads';

		$cacheFilename = static::getTemporaryPath() . static::$extKey . '.' . md5($sphinxUrl) . '.html';
		if (!file_exists($cacheFilename)
			|| $GLOBALS['EXEC_TIME'] - filemtime($cacheFilename) > 86400
			|| filesize($cacheFilename) == 0) {

			$html = MiscUtility::getUrl($sphinxUrl);
			GeneralUtility::writeFile($cacheFilename, $html);
		} else {
			$html = file_get_contents($cacheFilename);
		}

		$tagsHtml = substr($html, strpos($html, '<section class="tabs-pane" id="tag-downloads">'));
		$tagsHtml = substr($tagsHtml, 0, strpos($tagsHtml, '</section>'));

		$versions = array();
		preg_replace_callback(
			'#<tr class="iterable-item">.*?<td class="name">([^<]*)</td>.*?<a href="([^"]+)">zip</a>#s',
			function($matches) use (&$versions) {
				if ($matches[1] !== 'tip' && version_compare($matches[1], '1.1.3', '>=')) {
					$key = $matches[1];
					$name = $key;
					// Make sure main release (e.g., "1.2") gets a ".0" patch release version as well
					if (preg_match('/^\d+\.\d+$/', $name)) {
						$name .= '.0';
					}
					// Fix sorting of beta releases
					$name = str_replace('b', ' beta ', $name);

					$versions[$name] = array(
						'key' => $key,
						'name' => $name,
						'url' => $matches[2],
					);
				}
			},
			$tagsHtml
		);

		krsort($versions);
		return $versions;
	}

	/**
	 * Returns a list of locally available versions of Sphinx.
	 *
	 * @return array
	 */
	static public function getSphinxLocalVersions() {
		$sphinxPath = static::getSphinxPath();
		$versions = array();
		if (is_dir($sphinxPath)) {
			$versions = GeneralUtility::get_dirs($sphinxPath);
		}
		return $versions;
	}

	/**
	 * Logs and executes a command.
	 *
	 * @param string $cmd Command to be executed
	 * @param NULL|array $output Log of operations
	 * @param integer $returnValue Return code
	 * @return NULL|array Last line of the shell output
	 */
	static protected function exec($cmd, &$output = NULL, &$returnValue = 0) {
		static::$log[] = '[CMD] ' . $cmd;
		$lastLine = CommandUtility::exec($cmd, $out, $returnValue);
		static::$log = array_merge(static::$log, $out);
		$output = $out;
		return $lastLine;
	}

	/**
	 * Untars/Unzips an archive into a given target directory.
	 *
	 * @param string $archiveFilename Absolute path to the zip or tar.gz archive
	 * @param string $targetDirectory Absolute path to the target directory
	 * @param string|NULL $moveContentOutsideOfDirectoryPrefix Directory prefix to remove
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 */
	static public function unarchive($archiveFilename, $targetDirectory, $moveContentOutsideOfDirectoryPrefix = NULL, array &$output = NULL) {
		$success = FALSE;

		static::$log[] = '[INFO] Recreating directory ' . $targetDirectory;
		GeneralUtility::rmdir($targetDirectory, TRUE);
		GeneralUtility::mkdir_deep($targetDirectory . DIRECTORY_SEPARATOR);

		if (substr($archiveFilename, -4) === '.zip') {
			$unzip = escapeshellarg(CommandUtility::getCommand('unzip'));
			$cmd = $unzip . ' ' . escapeshellarg($archiveFilename) . ' -d ' . escapeshellarg($targetDirectory) . ' 2>&1';
			static::exec($cmd, $output, $ret);
		} else {
			if (CommandUtility::checkCommand('tar')) {
				$tar = escapeshellarg(CommandUtility::getCommand('tar'));
				$cmd = $tar . ' xzvf ' . escapeshellarg($archiveFilename) . ' -C ' . escapeshellarg($targetDirectory) . ' 2>&1';
				static::exec($cmd, $output, $ret);
			} else {
				// Fallback method
				try {
					// Remove similar .tar archives (possible garbage from previous run)
					$tarFilePattern = PathUtility::dirname($archiveFilename) . DIRECTORY_SEPARATOR;
					$tarFilePattern .= preg_replace('/(-[0-9.]+)?\.tar\.gz$/', '*.tar', PathUtility::basename($archiveFilename));
					$files = glob($tarFilePattern);
					if ($files === FALSE) {
						// An error occured
						$files = array();
					}
					foreach ($files as $file) {
						@unlink($file);
					}
					// Decompress from .gz
					$p = new \PharData($archiveFilename);
					$phar = $p->decompress();
					$phar->extractTo($targetDirectory);
					// Remove garbage
					$files = glob($tarFilePattern);
					foreach ($files as $file) {
						@unlink($file);
					}
					$ret = 0;
				} catch (\Exception $e) {
					$output[] = $e->getMessage();
					$ret = 1;
				}
			}
		}
		if ($ret === 0) {
			$success = TRUE;
			if ($moveContentOutsideOfDirectoryPrefix !== NULL) {
				// When unpacking the sources, content is located under a directory
				$directories = GeneralUtility::get_dirs($targetDirectory);
				if (GeneralUtility::isFirstPartOfStr($directories[0], $moveContentOutsideOfDirectoryPrefix)) {
					$fromDirectory = $targetDirectory . DIRECTORY_SEPARATOR . $directories[0];
					MiscUtility::recursiveCopy($fromDirectory, $targetDirectory);
					GeneralUtility::rmdir($fromDirectory, TRUE);

					// Remove tar.gz archive as we don't need it anymore
					@unlink($archiveFilename);
				} else {
					$success = FALSE;
				}
			}
		}

		return $success;
	}

	/**
	 * Builds a library with Python.
	 *
	 * @param string $name Name of the library
	 * @param string $setupFile Absolute path to the setup file
	 * @param string $pythonHome Absolute path to Python HOME
	 * @param string $pythonLib Absolute path to Python libraries
	 * @param NULL|array $output Log of operations
	 * @return boolean TRUE if operation succeeded, otherwise FALSE
	 */
	static protected function buildWithPython($name, $setupFile, $pythonHome, $pythonLib, array &$output = NULL) {
		$export = '';
		$clientInfo = GeneralUtility::clientInfo();
		if ($clientInfo['SYSTEM'] === 'mac') {
			// See http://forge.typo3.org/issues/58424
			$export = 'ARCHFLAGS=-Wno-error=unused-command-line-argument-hard-error-in-future ';
		}

		$python = $export . escapeshellarg(CommandUtility::getCommand('python'));
		$cmd = 'cd ' . escapeshellarg(PathUtility::dirname($setupFile)) . ' && ' .
			$python . ' setup.py clean 2>&1 && ' .
			$python . ' setup.py build 2>&1';
		$out = array();
		static::exec($cmd, $out, $ret);
		if ($ret === 0) {
			$cmd = 'cd ' . escapeshellarg(PathUtility::dirname($setupFile)) . ' && ' .
				MiscUtility::getExportCommand('PYTHONPATH', $pythonLib) . ' && ' .
				$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
			$out = array();
			static::exec($cmd, $out, $ret);
			if ($ret === 0) {
				$success = TRUE;
				$output[] = '[OK] ' . $name . ' successfully installed.';
			} else {
				$success = FALSE;
				$output[] = '[ERROR] Could not install ' . $name . ':' . LF . LF . implode($out, LF);
			}
		} else {
			$success = FALSE;
			$output[] = '[ERROR] Could not build ' . $name . ':' . LF . LF . implode($out, LF);
		}

		return $success;
	}

	/**
	 * Clears the log of operations.
	 *
	 * @return void
	 */
	static public function clearLog() {
		static::$log = array();
	}

	/**
	 * Dumps the log of operations.
	 *
	 * @param string $filename If empty, will return the complete log of operations instead of writing it to a file
	 * @return void|string
	 */
	static public function dumpLog($filename = '') {
		$content = implode(LF, static::$log);
		if ($filename) {
			$directory = PathUtility::dirname($filename);
			GeneralUtility::mkdir($directory);
			GeneralUtility::writeFile($filename, $content);
		} else {
			return $content;
		}
	}

	/**
	 * Returns the path to Sphinx sources base directory.
	 *
	 * @return string Absolute path to the Sphinx sources
	 */
	static private function getSphinxSourcesPath() {
		$sphinxSourcesPath = GeneralUtility::getFileAbsFileName('uploads/tx_sphinx/');
		// Compatibility with Windows platform
		$sphinxSourcesPath = str_replace('/', DIRECTORY_SEPARATOR, $sphinxSourcesPath);

		return $sphinxSourcesPath;
	}

	/**
	 * Returns the path to Sphinx binaries.
	 *
	 * @return string Absolute path to the Sphinx binaries
	 */
	static private function getSphinxPath() {
		$sphinxPath = GeneralUtility::getFileAbsFileName('typo3temp/tx_sphinx/sphinx-doc/');
		// Compatibility with Windows platform
		$sphinxPath = str_replace('/', DIRECTORY_SEPARATOR, $sphinxPath);

		return $sphinxPath;
	}

	/**
	 * Returns the path to the website's temporary directory.
	 *
	 * @return string Absolute path to typo3temp/
	 */
	static private function getTemporaryPath() {
		$temporaryPath = GeneralUtility::getFileAbsFileName('typo3temp/');
		// Compatibility with Windows platform
		$temporaryPath = str_replace('/', DIRECTORY_SEPARATOR, $temporaryPath);

		return $temporaryPath;
	}

}
