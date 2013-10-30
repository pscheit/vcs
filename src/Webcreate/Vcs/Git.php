<?php

/*
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 * @copyright Webcreate (http://webcreate.nl)
 */

namespace Webcreate\Vcs;

use Webcreate\Vcs\Common\VcsEvents;
use Webcreate\Vcs\Common\VcsFileInfo;
use Webcreate\Vcs\Common\Reference;
use Webcreate\Vcs\Common\Status;
use Webcreate\Vcs\Exception\NotFoundException;
use Webcreate\Vcs\Common\Commit;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Webcreate\Vcs\Git\AbstractGit;
use Webcreate\Vcs\VcsInterface;

/**
 * Git client
 *
 * Note: This class should only contain methods that are also in the
 * VcsInterface. Other methods should go into the AbstractGit class.
 *
 * @author Jeroen Fiege <jeroen@webcreate.nl>
 */
class Git extends AbstractGit implements VcsInterface
{
    const PRETTY_FORMAT = '<logentry><commit>%H</commit>%n<date>%aD</date>%n<author>%an</author>%n<msg><![CDATA[%B]]></msg></logentry>';

    /**
     * @var array remote tags cache
     */
    private $tags;

    /**
     * @var array remote branches cache
     */
    private $branches;

    /** @var bool ensures that we only fetch once */
    private $isFetched = false;

    public function cloneRepository($dest = null)
    {
        $temporary = $this->isTemporary;

        $realdest = (true === is_null($dest) ? $this->cwd : $dest);
        $branch = $this->getHead()->getName();

        $result = $this->execute('clone', array('-b' => (string) $branch, $this->url, $realdest));

        $this->setCwd($realdest);

        // the setCwd resets the isTemporary flag, this keeps it correct
        $this->isTemporary = $temporary;

        // a clone implies a fetch
        $this->isFetched = true;
    }

    /**
     * Perform a clone operation
     *
     * @param string|null $dest
     */
    public function checkout($dest = null)
    {
        $temporary = $this->isTemporary;

        if (true === is_null($dest)) {
            $realdest = $this->cwd;
        } else {
            $realdest = $dest;
        }

        $head = $this->getHead();
        $branch = $head->getName();

        $this->dispatch(VcsEvents::PRE_CHECKOUT, array('dest' => $realdest, 'head' => $head->getName()));

        if (false === $this->hasClone || false === is_null($dest)) {
            $this->cloneRepository($dest);
        } else {
            $result = $this->execute('checkout', array((string) $branch));
        }

        $this->hasCheckout = true;

        $result = $this->pull();

        $this->dispatch(VcsEvents::POST_CHECKOUT);
    }

    /**
     * @param  string            $path
     * @throws \RuntimeException
     * @return string
     */
    public function add($path)
    {
        if (!$this->hasCheckout) {
            throw new \RuntimeException('This operation requires an active checkout.');
        }

        return $this->execute('add', array($path));
    }

    /**
     * @param  string            $message
     * @throws \RuntimeException
     * @return string
     */
    public function commit($message)
    {
        if (!$this->hasCheckout) {
            throw new \RuntimeException('This operation requires an active checkout.');
        }

        return $this->execute('commit', array('-m' => $message));
    }

    /**
     * @param  null|string       $path
     * @throws \RuntimeException
     * @return string
     */
    public function status($path = null)
    {
        if (!$this->hasCheckout) {
            throw new \RuntimeException('This operation requires an active checkout.');
        }

        $args = array('--porcelain' => true);
        if (null !== $path) {
            $args[] = $path;
        }

        return $this->execute('status', $args);
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::import()
     */
    public function import($src, $path, $message)
    {
        if (!$this->hasCheckout) {
            $this->checkout();
        }

        $filesystem = new Filesystem();
        $filesystem->mirror($src, $this->cwd . $path);

        $test = $this->add('*');
        $test = $this->commit($message);
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::export()
     *
     * @todo handle $path parameter
     * @todo remove .git folders from submodules
     */
    public function export($path, $dest)
    {
        $head = $this->getHead();
        $branch = $head->getName();

        $this->dispatch(VcsEvents::PRE_EXPORT);

        if (!$this->hasCheckout) {
//            if ($this->isTemporary) {
//                // create a shallow checkout
//                $result = $this->execute('clone', array('-b' => (string) $branch, '--depth=1', $this->url, $dest));
//            } else {
//                $this->checkout();
//            }

            $this->checkout();
        }

        // we already have cloned the repository, use that instead
        $result = $this->execute('clone', array('-b' => (string) $branch, '--depth=1', 'file://' . $this->cwd, $dest), getcwd());

        $result = $this->adapter->execute('submodule', array('update', '--init' => true, '--recursive' => true), $dest);

        //$this->isTemporary = false;

        $filesystem = new Filesystem();
        $filesystem->remove($dest . '/.git');

        $this->dispatch(VcsEvents::POST_EXPORT);
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::ls()
     */
    public function ls($path)
    {
        if (!$this->hasCheckout) {
            $this->checkout();
        }

        if ($path == '/') {
            $path = '';
        }

        if (false === file_exists($this->cwd . '/' . $path)) {
            throw new NotFoundException(sprintf('The path \'%s\' is not found in %s', $path, $this->cwd));
        }

        $finder = new Finder();
        $files = $finder->in($this->cwd . '/' . $path)->exclude(".git")->ignoreDotFiles(false)->depth('== 0');

        $filelist = array();
        $entries = array();
        foreach ($files as $file) {
            $log = $this->log(($path ? $path . '/' : '') . $file->getRelativePathname(), null, 1);

            $commit = reset($log);
            $kind = $file->isDir() ? VcsFileInfo::DIR : VcsFileInfo::FILE;
            $file = new VcsFileInfo($file->getFilename(), $this->getHead(), $kind);
            $file->setCommit($commit);

            $entries[] = $file;
        }

        return $entries;
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::log()
     * @todo finish implementation for revision parameter
     */
    public function log($path, $revision = null, $limit = null)
    {
        $this->fetch();

        if ('' === $path) {
            $path = '.';
        }

        return $this->execute('log', array(
//                 '-r' => $revision ? $revision : false,
                '-n' => $limit ? $limit : false,
                '--pretty=' => self::PRETTY_FORMAT,
                $path
                )
        );
    }
    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::changelog()
     */
    public function changelog($revision1, $revision2)
    {
        $this->fetch();

        return $this->execute('log', array(
                '--pretty=' => self::PRETTY_FORMAT,
                sprintf('%s^1..%s', $revision1, $revision2),
            )
        );
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::cat()
     */
    public function cat($path)
    {
        if (!$this->hasCheckout) {
            $this->checkout();
        }

        if (false === file_exists($this->cwd . '/' . $path)) {
            throw new NotFoundException(sprintf('The path \'%s\' is not found', $path));
        }

        return file_get_contents($this->cwd . '/' . $path);
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::diff()
     *
     * @todo handle oldPath and newPath as References/VcsFileInfos:
     *     The command "/usr/local/bin/git diff --name-status '981abd298692d2ae531a904dbdab774589e05e3f' '981abd298692d2ae531a904dbdab774589e05e3f' 'master' 'refactor'" failed.
     *     usage: git diff [--no-index] <path> <path>
     */
    public function diff($oldPath, $newPath, $oldRevision = 'HEAD',
        $newRevision = 'HEAD', $summary = true)
    {
        $this->fetch();

        $arguments = array(
            '--name-status' => $summary,
            $oldRevision,
            $newRevision,
            $oldPath,
            $newPath
        );

        // filter null arguments (useful for optional oldPath and newPath arguments)
        $arguments = array_filter($arguments, function ($arg) {
            return (null !== $arg);
        });

        return $this->execute('diff', $arguments);
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::branches()
     */
    public function branches()
    {
        if (null === $this->branches) {
            $retval = $this->execute('ls-remote', array('--heads' => true, $this->getUrl()));

            if ('' === trim($retval)) {
                return $this->branches = array();
            }

            $list = explode("\n", rtrim($retval));

            $branches = array();
            foreach ($list as $line) {
                list ($hash, $ref) = explode("\t", $line);
                $branches[] = new Reference(basename($ref), Reference::BRANCH, $hash);
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * (non-PHPdoc)
     * @see Webcreate\Vcs.VcsInterface::tags()
     */
    public function tags()
    {
        if (null === $this->tags) {
            $retval = $this->execute('ls-remote', array('--tags' => true, $this->getUrl()));

            if ('' === trim($retval)) {
                return $this->tags = array();
            }

            $list = explode("\n", rtrim($retval));

            $tags = array();
            foreach ($list as $line) {
                list ($hash, $ref) = explode("\t", $line);
                $tags[] = new Reference(basename($ref), Reference::TAG, $hash);
            }

            $this->tags = $tags;
        }

        return $this->tags;
    }

    /**
     * Git push
     *
     * @param  string $remote
     * @throws \RuntimeException
     * @return null|string
     */
    public function push($remote = 'origin')
    {
        if (!$this->hasCheckout) {
            throw new \RuntimeException('This operation requires an active checkout.');
        }

        try {
            return $this->execute('push', array('--porcelain' => true, $remote));
        } catch (\RuntimeException $e) {
            // ignore output on stderr: it contains
            // progress information, for example about hooks
        }

        return null;
    }

    /**
     * Git pull
     *
     * @param  string $remote
     * @throws \RuntimeException
     * @return string
     */
    public function pull($remote = 'origin')
    {
        if (!$this->hasCheckout) {
            throw new \RuntimeException('This operation requires an active checkout.');
        }

        return $this->execute('pull', array($remote));
    }

    /**
     * Git fetch
     *
     * @param  string $remote
     * @throws \RuntimeException
     * @return bool
     */
    public function fetch($remote = null)
    {
        if ($this->isFetched) {
            return false;
        }

        if (!$this->hasClone) {
            $this->checkout();
        }

        $args = array();
        if (null !== $remote) {
            $args[] = $remote;
        }

        $this->execute('fetch', $args);

        $this->isFetched = true;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function revisionCompare($revision1, $revision2)
    {
        $this->fetch();

        if ($revision1 == $revision2) {
            return 0;
        } else {
            $result = (string) $this->execute('log', array('--pretty=' => 'oneline', sprintf('%s..%s', $revision1, $revision2)));

            if ('' === $result) {
                return 1;
            } else {
                return -1;
            }
        }
    }
}
