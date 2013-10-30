<?php

/*
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 * @copyright Webcreate (http://webcreate.nl)
 */

namespace Webcreate\Vcs\Test\Functional;

use Webcreate\Vcs\Test\Util\GitReposGenerator;
use Webcreate\Vcs\Git\Parser\CliParser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Webcreate\Util\Cli;
use Webcreate\Vcs\Common\Adapter\CliAdapter;
use Webcreate\Vcs\Git;

class GitTest extends AbstractTest
{
    public function getClient()
    {
        $this->tmpdir = sys_get_temp_dir() . '/' . uniqid('wbcrte-git-');

        $gitReposGenerator = new GitReposGenerator(__DIR__ . '/../Fixtures/skeleton/git/');
        list($this->vcsdir, $this->wcdir) = $gitReposGenerator->generate($this->tmpdir);

        $bin = getenv('GIT_BIN') ? getenv('GIT_BIN') : '/usr/local/bin/git';

        if (!file_exists($bin)) {
            $this->markTestSkipped(sprintf('GIT executable %s not found', $bin));
        }

        $parser = new CliParser();
        $adapter = new CliAdapter($bin, new Cli(), $parser);
        $client = new Git('file:///' . $this->vcsdir, $adapter);

        return $client;
    }

    public function existingPathProvider()
    {
        return array(
                array('README.md'),
        );
    }

    public function existingSubfolderProvider()
    {
        return array(
                array('dir1'),
        );
    }

    public function existingRevisionProvider()
    {
        $client = $this->client;

        $tmpdir = $this->tmpdir . '/' . uniqid();

        mkdir($tmpdir);

        $client->checkout($tmpdir);

        $touch = $tmpdir . '/test1.txt';
        file_put_contents($touch, 'sdfsd');

        $client->add('test1.txt');
        $client->commit('added test1.txt');

        $log = $client->log('');

        return array($log[0]->getRevision(), $log[1]->getRevision());
    }

    public function testGitExportRemovesGitFolder()
    {
        // we need to make sure the destination exists
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->exportDir);

        $this->client->export('', $this->exportDir);

        $this->assertFileNotExists($this->exportDir . '/.git');
    }

    public function tearDown()
    {
        parent::tearDown();

        $filesystem = new Filesystem();
        $filesystem->remove($this->tmpdir);
    }
}
