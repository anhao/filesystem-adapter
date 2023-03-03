<?php

/*
 * This file is part of php-cache organization.
 *
 * (c) 2015 Aaron Scherer <aequasi@gmail.com>, Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Cache\Adapter\Filesystem;

use Cache\Adapter\Common\AbstractCachePool;
use Cache\Adapter\Common\Exception\InvalidArgumentException;
use Cache\Adapter\Common\PhpCacheItem;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class FilesystemCachePool extends AbstractCachePool
{
    /**
     * @type FilesystemAdapter
     */
    private FilesystemAdapter $filesystem;

    /**
     * The folder should not begin nor end with a slash. Example: path/to/cache.
     *
     * @type string
     */
    private string $folder;

    /**
     * @param FilesystemAdapter $filesystem
     * @param string $folder
     * @throws FilesystemException
     */
    public function __construct(FilesystemAdapter $filesystem, string $folder = 'cache')
    {
        $this->folder = $folder;

        $this->filesystem = $filesystem;
        $this->filesystem->createDirectory($this->folder,$this->getFilesystemConfig());
    }

    /**
     * @param string $folder
     */
    public function setFolder(string $folder)
    {
        $this->folder = $folder;
    }

    /**
     * {@inheritdoc}
     */
    protected function fetchObjectFromCache($key): array
    {
        $empty = [false, null, [], null];
        $file  = $this->getFilePath($key);

        try {
            $data = @unserialize($this->filesystem->read($file));
            if ($data === false) {
                return $empty;
            }
        } catch (FilesystemException $e) {
            return $empty;
        }

        // Determine expirationTimestamp from data, remove items if expired
        $expirationTimestamp = $data[2] ?: null;
        if ($expirationTimestamp !== null && time() > $expirationTimestamp) {
            foreach ($data[1] as $tag) {
                $this->removeListItem($this->getTagKey($tag), $key);
            }
            $this->forceClear($key);

            return $empty;
        }

        return [true, $data[0], $data[1], $expirationTimestamp];
    }

    /**
     * {@inheritdoc}
     * @throws FilesystemException
     */
    protected function clearAllObjectsFromCache(): bool
    {
        $this->filesystem->deleteDirectory($this->folder);
        $this->filesystem->createDirectory($this->folder,$this->getFilesystemConfig());

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function clearOneObjectFromCache($key): bool
    {
        return $this->forceClear($key);
    }

    /**
     * {@inheritdoc}
     * @throws FilesystemException
     */
    protected function storeItemInCache(PhpCacheItem $item, $ttl): ?bool
    {
        $data = serialize(
            [
                $item->get(),
                $item->getTags(),
                $item->getExpirationTimestamp(),
            ]
        );

        $file = $this->getFilePath($item->getKey());
        if ($this->filesystem->fileExists($file)) {
            // Update file if it exists
             $this->filesystem->write($file, $data,$this->getFilesystemConfig());
        }

        try {
             $this->filesystem->write($file, $data,$this->getFilesystemConfig());
        } catch (FilesystemException $e) {
            // To handle issues when/if race conditions occurs, we try to update here.
             $this->filesystem->write($file, $data,$this->getFilesystemConfig());
        }
        return true;
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    private function getFilePath($key): string
    {
        if (!preg_match('|^[a-zA-Z0-9_\.! ]+$|', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid key "%s". Valid filenames must match [a-zA-Z0-9_\.! ].', $key));
        }

        return sprintf('%s/%s', $this->folder, $key);
    }

    /**
     * {@inheritdoc}
     */
    protected function getList($name)
    {
        $file = $this->getFilePath($name);

        if (!$this->filesystem->fileExists($file)) {
            $this->filesystem->write($file, serialize([]),$this->getFilesystemConfig());
        }

        return unserialize($this->filesystem->read($file));
    }

    /**
     * {@inheritdoc}
     * @throws FilesystemException
     */
    protected function removeList($name)
    {
        $file = $this->getFilePath($name);
        $this->filesystem->delete($file);
    }

    /**
     * {@inheritdoc}
     */
    protected function appendListItem($name, $key): bool
    {
        $list   = $this->getList($name);
        $list[] = $key;

         $this->filesystem->write($this->getFilePath($name), serialize($list),$this->getFilesystemConfig());
        return true;
    }

    /**
     * {@inheritdoc}
     * @throws FilesystemException
     */
    protected function removeListItem($name, $key): bool
    {
        $list = $this->getList($name);
        foreach ($list as $i => $item) {
            if ($item === $key) {
                unset($list[$i]);
            }
        }

         $this->filesystem->write($this->getFilePath($name), serialize($list),$this->getFilesystemConfig());
        return true;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    private function forceClear($key): bool
    {
        try {
             $this->filesystem->delete($this->getFilePath($key));
        } catch (FilesystemException $e) {
            return true;
        }
        return true;
    }

    private function getFilesystemConfig(): Config
    {
        return new Config([
            Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            Config::OPTION_VISIBILITY => 'public',
        ]);
    }
}
