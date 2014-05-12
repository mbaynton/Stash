<?php

/*
 * This file is part of the Stash package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Stash\Driver;

use Stash;
use Stash\Exception\RuntimeException;
use Stash\Interfaces\DriverInterface;

/**
 * The StashApc is a wrapper for the APC extension, which allows developers to store data in memory.
 *
 * @package Stash
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Apc implements DriverInterface
{
    protected $ttl = 300;
    protected $apcNamespace;
    protected $chunkSize = 100;

    /**
     * This function should takes an array which is used to pass option values to the driver.
     *
     * * ttl - This is the maximum time the item will be stored.
     * * namespace - This should be used when multiple projects may use the same library.
     *
     * @param  array                             $options
     * @throws \Stash\Exception\RuntimeException
     */
    public function __construct(array $options = array())
    {
        if (isset($options['ttl']) && is_numeric($options['ttl'])) {
            $this->ttl = (int) $options['ttl'];
        }

        $this->apcNamespace = isset($options['namespace']) ? $options['namespace'] : md5(__FILE__);

        if (!static::isAvailable()) {
            throw new RuntimeException('Extension is not installed.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function getData($key)
    {
        $keyString = self::makeKey($key);
        $success = null;
        $data = apc_fetch($keyString, $success);

        return $success ? $data : false;
    }

    /**
     * {@inheritdoc}
     */
    public function storeData($key, $data, $expiration)
    {
        $life = $this->getCacheTime($expiration);

        return apc_store($this->makeKey($key), array('data' => $data, 'expiration' => $expiration), $life);
    }

    /**
     * {@inheritdoc}
     */
    public function clear($key = null)
    {
        if (!isset($key)) {
            return apc_clear_cache('user');
        } else {
            $keyRegex = '[' . $this->makeKey($key) . '*]';
            $chunkSize = isset($this->chunkSize) && is_numeric($this->chunkSize) ? $this->chunkSize : 100;
            $it = new \APCIterator('user', $keyRegex, \APC_ITER_KEY, $chunkSize);
            foreach ($it as $key) {
                apc_delete($key);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function purge()
    {
        $now = time();
        $keyRegex = '[' . $this->makeKey(array()) . '*]';
        $chunkSize = isset($this->chunkSize) && is_numeric($this->chunkSize) ? $this->chunkSize : 100;
        $it = new \APCIterator('user', $keyRegex, \APC_ITER_KEY, $chunkSize);
        foreach ($it as $key) {
            $success = null;
            $data = apc_fetch($key, $success);
            $data = $data[$key['key']];

            if ($success && is_array($data) && $data['expiration'] <= $now) {
                apc_delete($key);
            }
        }

        return true;
    }

    /**
     * This driver is available if the apc extension is present and loaded on the system.
     *
     * @return bool
     */
    public static function isAvailable()
    {
        // HHVM has some of the APC extension, but not all of it.
        if (!class_exists('\APCIterator')) {
            return false;
        }

        return (extension_loaded('apc') && ini_get('apc.enabled'))
            && ((php_sapi_name() !== 'cli') || ini_get('apc.enable_cli'));
    }

    protected function makeKey($key)
    {
        $keyString = md5(__FILE__) . '::'; // make it unique per install

        if (isset($this->apcNamespace)) {
            $keyString .= $this->apcNamespace . '::';
        }

        foreach ($key as $piece) {
            $keyString .= $piece . '::';
        }

        return $keyString;
    }

    protected function getCacheTime($expiration)
    {
        $life = $expiration - time();

        return $this->ttl < $life ? $this->ttl : $life;
    }

}
