<?php
namespace Causal\Sphinx\Tests\Unit\Utility;

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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Causal\Sphinx\Utility\MiscUtility;

/**
 * Testcase for class \Causal\Sphinx\Utility\MiscUtility.
 */
class MiscUtilityTest extends \TYPO3\CMS\Core\Tests\UnitTestCase {

	/**
	 * @test
	 */
	public function canExtractMetadataForExtensionSphinx() {
		$metadata = MiscUtility::getExtensionMetaData('sphinx');
		$this->assertTrue(is_array($metadata));
		$this->assertSame(27, count($metadata));
		$this->assertSame('sphinx', $metadata['extensionKey']);
		$this->assertSame('Xavier Perseguers (Causal)', $metadata['author']);
		$this->assertSame('Causal Sàrl', $metadata['author_company']);
		$this->assertSame('xavier@causal.ch', $metadata['author_email']);
		$this->assertSame('6.0.0-6.2.99', $metadata['constraints']['depends']['typo3']);
		$this->assertFalse(empty($metadata['release']));
		$this->assertFalse(empty($metadata['version']));
	}

	/**
	 * @test
	 */
	public function extensionSphinxHasSphinxDocumentation() {
		$documentationType = MiscUtility::getDocumentationType('sphinx');
		$this->assertSame(MiscUtility::DOCUMENTATION_TYPE_SPHINX, $documentationType);
	}

	/**
	 * @test
	 */
	public function extensionAboutHasUnknownDocumentation() {
		$documentationType = MiscUtility::getDocumentationType('about');
		$this->assertSame(MiscUtility::DOCUMENTATION_TYPE_UNKNOWN, $documentationType);
	}

	/**
	 * @test
	 */
	public function extensionDocumentationHasREADMEDocumentation() {
		if (version_compare(TYPO3_version, '6.1.99', '<=')) {
			$this->markTestIncomplete(
				'This test can only run with TYPO3 6.2 LTS and above.'
			);
		}

		$documentationType = MiscUtility::getDocumentationType('documentation');
		$this->assertSame(MiscUtility::DOCUMENTATION_TYPE_README, $documentationType);
	}

	/**
	 * @test
	 */
	public function extensionSphinxHasFrenchDocumentation() {
		$localizationDirectories = MiscUtility::getLocalizationDirectories('sphinx');
		$expected = array(
			'fr'    => array(
				'directory' => 'Documentation/Localization.fr_FR',
				'locale'    => 'fr_FR',
			),
			'fr_FR' => array(
				'directory' => 'Documentation/Localization.fr_FR',
				'locale'    => 'fr_FR',
			),
		);
		$this->assertSame($expected, $localizationDirectories);
	}

	/**
	 * @test
	 */
	public function extensionSphinxHasSphinxFrenchDocumentation() {
		$documentationType = MiscUtility::getLocalizedDocumentationType('sphinx', 'fr_FR');
		$this->assertSame(MiscUtility::DOCUMENTATION_TYPE_SPHINX, $documentationType);
	}

	/**
	 * @test
	 */
	public function extensionDocumentationHasNoFrenchDocumentation() {
		if (version_compare(TYPO3_version, '6.1.99', '<=')) {
			$this->markTestIncomplete(
				'This test can only run with TYPO3 6.2 LTS and above.'
			);
		}

		$documentationType = MiscUtility::getLocalizedDocumentationType('documentation', 'fr_FR');
		$this->assertSame(MiscUtility::DOCUMENTATION_TYPE_UNKNOWN, $documentationType);
	}

	/**
	 * @test
	 */
	public function canExtractEnglishDocumentationTitleForExtensionSphinx() {
		$projectTitle = MiscUtility::getDocumentationProjectTitle('sphinx');
		$this->assertSame('Sphinx Python Documentation Generator and Viewer', $projectTitle);
	}

	/**
	 * @test
	 */
	public function canExtractFrenchDocumentationTitleForExtensionSphinx() {
		$projectTitle = MiscUtility::getDocumentationProjectTitle('sphinx', 'fr_FR');
		$this->assertSame('Générateur et visionneuse de documentation Sphinx Python', $projectTitle);
	}

	/**
	 * @test
	 */
	public function cannotExtractGermanDocumentationTitleForExtensionSphinx() {
		$projectTitle = MiscUtility::getDocumentationProjectTitle('sphinx', 'de_DE');
		$this->assertEmpty($projectTitle);
	}

	/**
	 * @test
	 */
	public function canParseBasicYaml() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
# This is the project specific Settings.yml file.
# Place Sphinx specific build information here.
# Settings given here will replace the settings of 'conf.py'.

conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			'copyright = u\'2014\'',
			'project = u\'Sphinx Python Documentation Generator and Viewer\'',
			'version = u\'1.2\'',
			'release = u\'1.2.0-dev\'',
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canCreateInitialIntersphinxMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		MiscUtility::addIntersphinxMapping(
			$fixtureFilename,
			'restdoc',
			'http://docs.typo3.org/typo3cms/extensions/restdoc/'
		);
		$configuration = file_get_contents($fixtureFilename);
		$expected = <<<YAML
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
  intersphinx_mapping:
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
YAML;
		$this->assertSame($expected, $configuration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canCreateInitialIntersphinxMappingWithCommentsAndDelimiters() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
# This is the project specific Settings.yml file.
# Place Sphinx specific build information here.
# Settings given here will replace the settings of 'conf.py'.

---
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
...

YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		MiscUtility::addIntersphinxMapping(
			$fixtureFilename,
			'restdoc',
			'http://docs.typo3.org/typo3cms/extensions/restdoc/'
		);
		$configuration = file_get_contents($fixtureFilename);
		$expected = <<<YAML
# This is the project specific Settings.yml file.
# Place Sphinx specific build information here.
# Settings given here will replace the settings of 'conf.py'.

---
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
  intersphinx_mapping:
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
...

YAML;
		$this->assertSame($expected, $configuration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canCreateNewSettingsYamlWithIntersphinxMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		if (is_file($fixtureFilename)) {
			unlink($fixtureFilename);
		}

		// Test
		MiscUtility::addIntersphinxMapping(
			$fixtureFilename,
			'restdoc',
			'http://docs.typo3.org/typo3cms/extensions/restdoc/'
		);
		$configuration = file_get_contents($fixtureFilename);
		$expected = <<<YAML
# This is the project specific Settings.yml file.
# Place Sphinx specific build information here.
# Settings given here will replace the settings of 'conf.py'.

---
conf.py:
  copyright: 2014
  project: No project name
  version: 1.0
  release: 1.0.0
  intersphinx_mapping:
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
...

YAML;
		$this->assertSame($expected, $configuration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canAddNewIntersphinxMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
  intersphinx_mapping:
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		MiscUtility::addIntersphinxMapping(
			$fixtureFilename,
			't3cmsapi',
			'http://typo3.org/api/typo3cms'
		);
		$configuration = file_get_contents($fixtureFilename);
		$expected = <<<YAML
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
  intersphinx_mapping:
    t3cmsapi:
    - http://typo3.org/api/typo3cms/
    - null
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
YAML;
		$this->assertSame($expected, $configuration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function existingMappingIsNotAddedAgain() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  copyright: 2014
  project: Sphinx Python Documentation Generator and Viewer
  version: 1.2
  release: 1.2.0-dev
  intersphinx_mapping:
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		MiscUtility::addIntersphinxMapping(
			$fixtureFilename,
			'restdoc',
			'http://docs.typo3.org/typo3cms/extensions/restdoc/'
		);
		$configuration = file_get_contents($fixtureFilename);
		$expected = $yaml;
		$this->assertSame($expected, $configuration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canParseLaTeXYamlConfiguration() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  latex_documents:
  - - Index
    - sphinx.tex
    - Sphinx Python Documentation Generator and Viewer
    - Xavier Perseguers
    - manual
  latex_elements:
    papersize: a4paper
    pointsize: 10pt
    preamble: \usepackage{typo3}
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			'latex_documents = [(' . LF .
				"u'Index'," . LF .
				"u'sphinx.tex'," . LF .
				"u'Sphinx Python Documentation Generator and Viewer'," . LF .
				"u'Xavier Perseguers'," . LF .
				"u'manual'" . LF .
			')]',
			'latex_elements = {' . LF .
				"'papersize': 'a4paper'," . LF .
				"'pointsize': '10pt'," . LF .
				"'preamble': '\\\\usepackage{typo3}'" . LF .
			'}',
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canParseIntersphinxYamlMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  intersphinx_mapping:
    t3tsref:
    - http://docs.typo3.org/typo3cms/TyposcriptReference/
    - null
    restdoc:
    - http://docs.typo3.org/typo3cms/extensions/restdoc/
    - null
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			'intersphinx_mapping = {' . LF .
				"'t3tsref': ('http://docs.typo3.org/typo3cms/TyposcriptReference/', None)," . LF .
				"'restdoc': ('http://docs.typo3.org/typo3cms/extensions/restdoc/', None)" . LF .
			'}',
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canParseSingleExtlinksYamlMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  extlinks:
    issue:
    - https://bitbucket.org/birkenfeld/sphinx/issue/%s
    -     'issue '
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			'extlinks = {' . LF .
			"'issue': ('https://bitbucket.org/birkenfeld/sphinx/issue/%s', 'issue ')" . LF .
			'}',
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canParseDoubleExtlinksYamlMapping() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  extlinks:
    forge:
    - https://forge.typo3.org/issues/%s
    - 'forge: '
    ter:
    - http://www.typo3.org/extensions/repository/view/%s
    - null
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			'extlinks = {' . LF .
			"'forge': ('https://forge.typo3.org/issues/%s', 'forge: ')," . LF .
			"'ter': ('http://www.typo3.org/extensions/repository/view/%s', None)" . LF .
			'}',
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

	/**
	 * @test
	 */
	public function canParseExtensionsConfiguration() {
		// Setup
		$fixtureFilename = tempnam(PATH_site . 'typo3temp', 'sphinx');
		$yaml = <<<YAML
conf.py:
  extensions:
  - sphinx.ext.intersphinx
  - t3sphinx.ext.t3extras
  - sphinxcontrib.youtube
YAML;
		GeneralUtility::writeFile($fixtureFilename, $yaml);

		// Test
		$pythonConfiguration = MiscUtility::yamlToPython($fixtureFilename);
		$expected = array(
			"extensions = ['sphinx.ext.intersphinx', 'sphinxcontrib.youtube']"
		);
		$this->assertSame($expected, $pythonConfiguration);

		// Tear down
		@unlink($fixtureFilename);
	}

}
