<?php

/*
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 * @copyright Webcreate (http://webcreate.nl)
 */

use Webcreate\Vcs\Common\VcsFileInfo;
use Webcreate\Vcs\Common\Reference;
use Webcreate\Vcs\Common\Commit;
use Symfony\Component\Filesystem\Filesystem;
use Webcreate\Vcs\Git;

class GitTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->username = 'user';
        $this->password = 'userpass';
        $this->url = 'https://github.com/fieg/dotfiles.git';
        $this->bin = '/usr/local/bin/git';
        $this->tmpdir = sys_get_temp_dir() . '/' . uniqid(time());

        $this->parser = $this->getMock('Webcreate\\Vcs\\Git\\Parser\\CliParser', null);
        $this->cli = $this->getMock('Webcreate\\Util\\Cli', array('execute', 'getOutput', 'getErrorOutput'));
        $this->adapter = $this->getMock('Webcreate\\Vcs\Common\\Adapter\\CliAdapter', null, array($this->bin, $this->cli, $this->parser));
        $this->git = $this->getMockBuilder('Webcreate\\Vcs\\Git')
            ->setConstructorArgs(array($this->url, $this->adapter, $this->tmpdir))
            ->setMethods(null)
        ;
        $this->isWindows = defined('PHP_WINDOWS_VERSION_MAJOR');
        $this->cliQuote = $this->isWindows ? '"' : "'";
    }

    public function testCheckoutCommandline()
    {
        $expected = sprintf('%s clone -b %s %s %s', $this->bin, $this->quote('master'), $this->quote($this->url), $this->quote($this->tmpdir));

        $tmpdir = $this->tmpdir;

        $this->cli
            ->expects($this->at(0))
            ->method('execute')
            ->with($expected)
            ->will($this->returnCallback(function() use ($tmpdir) {
                $filesystem = new Filesystem();
                $filesystem->mkdir($tmpdir);
            }))
        ;

        $result = $this->git->getMock()->checkout($this->tmpdir);
    }

    public function testLsListsFilesFromCheckout()
    {
        $git = $this->git
            ->setMethods(array('log'))
            ->getMock()
        ;

        $git
            ->expects($this->once())
            ->method('log')
            ->will($this->returnValue(array($commit = new Commit('cf52a6c', new \DateTime(), 'jeroen'))))
        ;

        $tmpdir = $this->tmpdir;

        $this->cli
            ->expects($this->at(0))
            ->method('execute')
            ->with(sprintf('%s clone -b %s %s %s', $this->bin, $this->quote('master'), $this->quote($this->url), $this->quote($this->tmpdir)))
            ->will($this->returnCallback(function() use ($tmpdir) {
                $filesystem = new Filesystem();
                $filesystem->mkdir($tmpdir);
                $filesystem->mirror(__DIR__ . '/Test/Fixtures/skeleton/git', $tmpdir);
            }))
        ;

        $result = $git->ls('/dir1');

        $expected = new VcsFileInfo('sample1.php', new Reference('master'), VcsFileInfo::FILE);
        $expected->setCommit($commit);

        $this->assertCount(1, $result);
        $this->assertContainsOnlyInstancesOf('Webcreate\\Vcs\\Common\\VcsFileInfo', $result);
        $this->assertEquals($expected, $result[0]);
    }

    /**
     * @dataProvider logProvider
     */
    public function testLogCommandline($path, $revision, $limit, $expected)
    {
        $this->git->setMethods(array('checkout'));
        $this->cli
            ->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expected))
        ;

        $result = $this->git->getMock()->log($path, $revision, $limit);
    }

    public function logProvider()
    {
        $this->setUp();

        return array(
                array('/dir1', null, 10, sprintf("%s log -n %s --pretty=%s %s",
                        $this->bin,
                        $this->quote(10),
                        $this->quote(Git::PRETTY_FORMAT),
                        $this->quote('/dir1')
                )),
                array('/dir1', null, null, sprintf('%s log --pretty=%s %s',
                        $this->bin,
                        $this->quote(Git::PRETTY_FORMAT),
                        $this->quote('/dir1')
                )),
        );
    }

    public function testCatReadsFileFromCheckout()
    {
        $git = $this->git
            ->getMock()
        ;

        $tmpdir = $this->tmpdir;

        $this->cli
            ->expects($this->at(0))
            ->method('execute')
            ->with(sprintf('%s clone -b %s %s %s', $this->bin, $this->quote('master'), $this->quote($this->url), $this->quote($this->tmpdir)))
            ->will($this->returnCallback(function() use ($tmpdir) {
                $filesystem = new Filesystem();
                $filesystem->mkdir($tmpdir);
                $filesystem->mirror(__DIR__ . '/Test/Fixtures/skeleton/git', $tmpdir);
            }))
        ;

        $result = $git->cat('Hello.txt');
        $this->assertEquals('Hello world', $result);
    }

    public function testImport()
    {
        $git = $this->git
            ->setMethods(array('add', 'commit', 'checkout'))
            ->getMock()
        ;

        $git
            ->expects($this->once())
            ->method('checkout')
        ;

        $git
            ->expects($this->once())
            ->method('add')
        ;

        $git
            ->expects($this->once())
            ->method('add')
        ;

        $result = $git->import(__DIR__ . '/Test/Fixtures', '/dir1', 'test importing');
    }

    public function tearDown()
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tmpdir);
    }

    protected function quote($argument)
    {
        return $this->cliQuote.$argument.$this->cliQuote;
    }
}


