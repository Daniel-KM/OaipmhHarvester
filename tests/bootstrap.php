<?php
/**
 * OaipmhHarvester bootstrap
 *
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package OaipmhHarvester
 */
define('OAIPMHHARVESTER_BASE', realpath(dirname(__FILE__) . '/../'));
set_include_path(get_include_path() . PATH_SEPARATOR . OAIPMHHARVESTER_BASE);

define('OAIPMH_HARVESTER_DIR', dirname(dirname(__FILE__)));
define('TEST_FILES_DIR', OAIPMH_HARVESTER_DIR
    . DIRECTORY_SEPARATOR . 'tests'
    . DIRECTORY_SEPARATOR . 'suite'
    . DIRECTORY_SEPARATOR . '_files');
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/application/tests/bootstrap.php';
require_once 'OaipmhHarvester_Test_AppTestCase.php';
