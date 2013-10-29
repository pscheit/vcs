<?php

/*
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 * @copyright Webcreate (http://webcreate.nl)
 */

use Webcreate\Util\Cli;
use Webcreate\Vcs\Svn\Svnadmin;

class SvnadminTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->svndir = sys_get_temp_dir();
        $this->isWindows = defined('PHP_WINDOWS_VERSION_MAJOR');
        $this->cliQuote = $this->isWindows ? '"' : "'";
    }

    public function testCreate()
    {
        $cli = $this->getMock('Webcreate\\Util\\Cli', array('execute', 'getOutput', 'getErrorOutput'));
        $cli
            ->expects($this->once())
            ->method('execute')
            ->with('/usr/local/bin/svnadmin create '.$this->cliQuote.$this->svndir.'/test_test'.$this->cliQuote)
            ->will($this->returnValue(0))
        ;

        $svnadmin = new Svnadmin($this->svndir, '/usr/local/bin/svnadmin', $cli);
        $svnadmin->create('test_test');
    }
}