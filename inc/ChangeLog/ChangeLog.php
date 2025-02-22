<?php

namespace dokuwiki\ChangeLog;

use dokuwiki\Logger;

/**
 * ChangeLog Prototype; methods for handling changelog
 */
abstract class ChangeLog
{
    use ChangeLogTrait;

    /** @var string */
    protected $id;
    /** @var false|int */
    protected $currentRevision;
    /** @var array */
    protected $cache;

    /**
     * Constructor
     *
     * @param string $id page id
     * @param int $chunk_size maximum block size read from file
     */
    public function __construct($id, $chunk_size = 8192)
    {
        global $cache_revinfo;

        $this->cache =& $cache_revinfo;
        if (!isset($this->cache[$id])) {
            $this->cache[$id] = array();
        }

        $this->id = $id;
        $this->setChunkSize($chunk_size);
    }

    /**
     * Returns path to current page/media
     *
     * @return string path to file
     */
    abstract protected function getFilename();

    /**
     * Check whether given revision is the current page
     *
     * @param int $rev timestamp of current page
     * @return bool true if $rev is current revision, otherwise false
     */
    public function isCurrentRevision($rev)
    {
        return $rev == $this->currentRevision();
    }

    /**
     * Checks if the revision is last revision
     *
     * @param int $rev revision timestamp
     * @return bool true if $rev is last revision, otherwise false
     */
    public function isLastRevision($rev = null)
    {
        return $rev === $this->lastRevision();
    }

    /**
     * Return the current revision identifer
     *
     * The "current" revision means current version of the page or media file. It is either
     * identical with or newer than the "last" revision, that depends on whether the file
     * has modified, created or deleted outside of DokuWiki.
     * The value of identifier can be determined by timestamp as far as the file exists,
     * otherwise it must be assigned larger than any other revisions to keep them sortable.
     *
     * @return int|false revision timestamp
     */
    public function currentRevision()
    {
        if (!isset($this->currentRevision)) {
            // set ChangeLog::currentRevision property
            $this->getCurrentRevisionInfo();
        }
        return $this->currentRevision;
    }

    /**
     * Return the last revision identifer, date value of the last entry of the changelog
     *
     * @return int|false revision timestamp
     */
    public function lastRevision()
    {
        $revs = $this->getRevisions(-1, 1);
        return empty($revs) ? false : $revs[0];
    }

    /**
     * Save revision info to the cache pool
     *
     * @param array $info Revision info structure
     * @return bool
     */
    protected function cacheRevisionInfo($info)
    {
        if (!is_array($info)) return false;
        //$this->cache[$this->id][$info['date']] ??= $info; // since php 7.4
        $this->cache[$this->id][$info['date']] = $this->cache[$this->id][$info['date']] ?? $info;
        return true;
    }

    /**
     * Get the changelog information for a specific revision (timestamp)
     *
     * Adjacent changelog lines are optimistically parsed and cached to speed up
     * consecutive calls to getRevisionInfo. For large changelog files, only the chunk
     * containing the requested changelog line is read.
     *
     * @param int $rev revision timestamp
     * @param bool $retrieveCurrentRevInfo allows to skip for getting other revision info in the
     *                                     getCurrentRevisionInfo() where $currentRevision is not yet determined
     * @return bool|array false or array with entries:
     *      - date:  unix timestamp
     *      - ip:    IPv4 address (127.0.0.1)
     *      - type:  log line type
     *      - id:    page id
     *      - user:  user name
     *      - sum:   edit summary (or action reason)
     *      - extra: extra data (varies by line type)
     *      - sizechange: change of filesize
     *
     * @author Ben Coburn <btcoburn@silicodon.net>
     * @author Kate Arzamastseva <pshns@ukr.net>
     */
    public function getRevisionInfo($rev, $retrieveCurrentRevInfo = true)
    {
        $rev = max(0, $rev);
        if (!$rev) return false;

        //ensure the external edits are cached as well
        if (!isset($this->currentRevision) && $retrieveCurrentRevInfo) {
            $this->getCurrentRevisionInfo();
        }

        // check if it's already in the memory cache
        if (isset($this->cache[$this->id]) && isset($this->cache[$this->id][$rev])) {
            return $this->cache[$this->id][$rev];
        }

        //read lines from changelog
        list($fp, $lines) = $this->readloglines($rev);
        if ($fp) {
            fclose($fp);
        }
        if (empty($lines)) return false;

        // parse and cache changelog lines
        foreach ($lines as $value) {
            $info = $this->parseLogLine($value);
            $this->cacheRevisionInfo($info);
        }
        if (!isset($this->cache[$this->id][$rev])) {
            return false;
        }
        return $this->cache[$this->id][$rev];
    }

    /**
     * Return a list of page revisions numbers
     *
     * Does not guarantee that the revision exists in the attic,
     * only that a line with the date exists in the changelog.
     * By default the current revision is skipped.
     *
     * The current revision is automatically skipped when the page exists.
     * See $INFO['meta']['last_change'] for the current revision.
     * A negative $first let read the current revision too.
     *
     * For efficiency, the log lines are parsed and cached for later
     * calls to getRevisionInfo. Large changelog files are read
     * backwards in chunks until the requested number of changelog
     * lines are recieved.
     *
     * @param int $first skip the first n changelog lines
     * @param int $num number of revisions to return
     * @return array with the revision timestamps
     *
     * @author Ben Coburn <btcoburn@silicodon.net>
     * @author Kate Arzamastseva <pshns@ukr.net>
     */
    public function getRevisions($first, $num)
    {
        $revs = array();
        $lines = array();
        $count = 0;

        $logfile = $this->getChangelogFilename();
        if (!file_exists($logfile)) return $revs;

        $num = max($num, 0);
        if ($num == 0) {
            return $revs;
        }

        if ($first < 0) {
            $first = 0;
        } else {
            $fileLastMod = $this->getFilename();
            if (file_exists($fileLastMod) && $this->isLastRevision(filemtime($fileLastMod))) {
                // skip last revision if the page exists
                $first = max($first + 1, 0);
            }
        }

        if (filesize($logfile) < $this->chunk_size || $this->chunk_size == 0) {
            // read whole file
            $lines = file($logfile);
            if ($lines === false) {
                return $revs;
            }
        } else {
            // read chunks backwards
            $fp = fopen($logfile, 'rb'); // "file pointer"
            if ($fp === false) {
                return $revs;
            }
            fseek($fp, 0, SEEK_END);
            $tail = ftell($fp);

            // chunk backwards
            $finger = max($tail - $this->chunk_size, 0);
            while ($count < $num + $first) {
                $nl = $this->getNewlinepointer($fp, $finger);

                // was the chunk big enough? if not, take another bite
                if ($nl > 0 && $tail <= $nl) {
                    $finger = max($finger - $this->chunk_size, 0);
                    continue;
                } else {
                    $finger = $nl;
                }

                // read chunk
                $chunk = '';
                $read_size = max($tail - $finger, 0); // found chunk size
                $got = 0;
                while ($got < $read_size && !feof($fp)) {
                    $tmp = @fread($fp, max(min($this->chunk_size, $read_size - $got), 0));
                    if ($tmp === false) {
                        break;
                    } //error state
                    $got += strlen($tmp);
                    $chunk .= $tmp;
                }
                $tmp = explode("\n", $chunk);
                array_pop($tmp); // remove trailing newline

                // combine with previous chunk
                $count += count($tmp);
                $lines = array_merge($tmp, $lines);

                // next chunk
                if ($finger == 0) {
                    break;
                } else { // already read all the lines
                    $tail = $finger;
                    $finger = max($tail - $this->chunk_size, 0);
                }
            }
            fclose($fp);
        }

        // skip parsing extra lines
        $num = max(min(count($lines) - $first, $num), 0);
        if ($first > 0 && $num > 0) {
            $lines = array_slice($lines, max(count($lines) - $first - $num, 0), $num);
        } elseif ($first > 0 && $num == 0) {
            $lines = array_slice($lines, 0, max(count($lines) - $first, 0));
        } elseif ($first == 0 && $num > 0) {
            $lines = array_slice($lines, max(count($lines) - $num, 0));
        }

        // handle lines in reverse order
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $info = $this->parseLogLine($lines[$i]);
            if ($this->cacheRevisionInfo($info)) {
                $revs[] = $info['date'];
            }
        }

        return $revs;
    }

    /**
     * Get the nth revision left or right handside  for a specific page id and revision (timestamp)
     *
     * For large changelog files, only the chunk containing the
     * reference revision $rev is read and sometimes a next chunck.
     *
     * Adjacent changelog lines are optimistically parsed and cached to speed up
     * consecutive calls to getRevisionInfo.
     *
     * @param int $rev revision timestamp used as startdate
     *    (doesn't need to be exact revision number)
     * @param int $direction give position of returned revision with respect to $rev;
          positive=next, negative=prev
     * @return bool|int
     *      timestamp of the requested revision
     *      otherwise false
     */
    public function getRelativeRevision($rev, $direction)
    {
        $rev = max($rev, 0);
        $direction = (int)$direction;

        //no direction given or last rev, so no follow-up
        if (!$direction || ($direction > 0 && $this->isCurrentRevision($rev))) {
            return false;
        }

        //get lines from changelog
        list($fp, $lines, $head, $tail, $eof) = $this->readloglines($rev);
        if (empty($lines)) return false;

        // look for revisions later/earlier than $rev, when founded count till the wanted revision is reached
        // also parse and cache changelog lines for getRevisionInfo().
        $revcounter = 0;
        $relativerev = false;
        $checkotherchunck = true; //always runs once
        while (!$relativerev && $checkotherchunck) {
            $info = array();
            //parse in normal or reverse order
            $count = count($lines);
            if ($direction > 0) {
                $start = 0;
                $step = 1;
            } else {
                $start = $count - 1;
                $step = -1;
            }
            for ($i = $start; $i >= 0 && $i < $count; $i = $i + $step) {
                $info = $this->parseLogLine($lines[$i]);
                if ($this->cacheRevisionInfo($info)) {
                    //look for revs older/earlier then reference $rev and select $direction-th one
                    if (($direction > 0 && $info['date'] > $rev) || ($direction < 0 && $info['date'] < $rev)) {
                        $revcounter++;
                        if ($revcounter == abs($direction)) {
                            $relativerev = $info['date'];
                        }
                    }
                }
            }

            //true when $rev is found, but not the wanted follow-up.
            $checkotherchunck = $fp
                && ($info['date'] == $rev || ($revcounter > 0 && !$relativerev))
                && !(($tail == $eof && $direction > 0) || ($head == 0 && $direction < 0));

            if ($checkotherchunck) {
                list($lines, $head, $tail) = $this->readAdjacentChunk($fp, $head, $tail, $direction);

                if (empty($lines)) break;
            }
        }
        if ($fp) {
            fclose($fp);
        }

        return $relativerev;
    }

    /**
     * Returns revisions around rev1 and rev2
     * When available it returns $max entries for each revision
     *
     * @param int $rev1 oldest revision timestamp
     * @param int $rev2 newest revision timestamp (0 looks up last revision)
     * @param int $max maximum number of revisions returned
     * @return array with two arrays with revisions surrounding rev1 respectively rev2
     */
    public function getRevisionsAround($rev1, $rev2, $max = 50)
    {
        $max = intval(abs($max) / 2) * 2 + 1;
        $rev1 = max($rev1, 0);
        $rev2 = max($rev2, 0);

        if ($rev2) {
            if ($rev2 < $rev1) {
                $rev = $rev2;
                $rev2 = $rev1;
                $rev1 = $rev;
            }
        } else {
            //empty right side means a removed page. Look up last revision.
            $rev2 = $this->currentRevision();
        }
        //collect revisions around rev2
        list($revs2, $allrevs, $fp, $lines, $head, $tail) = $this->retrieveRevisionsAround($rev2, $max);

        if (empty($revs2)) return array(array(), array());

        //collect revisions around rev1
        $index = array_search($rev1, $allrevs);
        if ($index === false) {
            //no overlapping revisions
            list($revs1, , , , ,) = $this->retrieveRevisionsAround($rev1, $max);
            if (empty($revs1)) $revs1 = array();
        } else {
            //revisions overlaps, reuse revisions around rev2
            $lastrev = array_pop($allrevs); //keep last entry that could be external edit
            $revs1 = $allrevs;
            while ($head > 0) {
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $info = $this->parseLogLine($lines[$i]);
                    if ($this->cacheRevisionInfo($info)) {
                        $revs1[] = $info['date'];
                        $index++;

                        if ($index > intval($max / 2)) break 2;
                    }
                }

                list($lines, $head, $tail) = $this->readAdjacentChunk($fp, $head, $tail, -1);
            }
            sort($revs1);
            $revs1[] = $lastrev; //push back last entry

            //return wanted selection
            $revs1 = array_slice($revs1, max($index - intval($max / 2), 0), $max);
        }

        return array(array_reverse($revs1), array_reverse($revs2));
    }

    /**
     * Return an existing revision for a specific date which is
     * the current one or younger or equal then the date
     *
     * @param number $date_at timestamp
     * @return string revision ('' for current)
     */
    public function getLastRevisionAt($date_at)
    {
        $fileLastMod = $this->getFilename();
        //requested date_at(timestamp) younger or equal then modified_time($this->id) => load current
        if (file_exists($fileLastMod) && $date_at >= @filemtime($fileLastMod)) {
            return '';
        } else {
            if ($rev = $this->getRelativeRevision($date_at + 1, -1)) { //+1 to get also the requested date revision
                return $rev;
            } else {
                return false;
            }
        }
    }

    /**
     * Collect the $max revisions near to the timestamp $rev
     *
     * Ideally, half of retrieved timestamps are older than $rev, another half are newer.
     * The returned array $requestedrevs may not contain the reference timestamp $rev
     * when it does not match any revision value recorded in changelog.
     *
     * @param int $rev revision timestamp
     * @param int $max maximum number of revisions to be returned
     * @return bool|array
     *     return array with entries:
     *       - $requestedrevs: array of with $max revision timestamps
     *       - $revs: all parsed revision timestamps
     *       - $fp: filepointer only defined for chuck reading, needs closing.
     *       - $lines: non-parsed changelog lines before the parsed revisions
     *       - $head: position of first readed changelogline
     *       - $lasttail: position of end of last readed changelogline
     *     otherwise false
     */
    protected function retrieveRevisionsAround($rev, $max)
    {
        $revs = array();
        $aftercount = $beforecount = 0;

        //get lines from changelog
        list($fp, $lines, $starthead, $starttail, $eof) = $this->readloglines($rev);
        if (empty($lines)) return false;

        //parse changelog lines in chunk, and read forward more chunks until $max/2 is reached
        $head = $starthead;
        $tail = $starttail;
        while (count($lines) > 0) {
            foreach ($lines as $line) {
                $info = $this->parseLogLine($line);
                if ($this->cacheRevisionInfo($info)) {
                    $revs[] = $info['date'];
                    if ($info['date'] >= $rev) {
                        //count revs after reference $rev
                        $aftercount++;
                        if ($aftercount == 1) $beforecount = count($revs);
                    }
                    //enough revs after reference $rev?
                    if ($aftercount > intval($max / 2)) break 2;
                }
            }
            //retrieve next chunk
            list($lines, $head, $tail) = $this->readAdjacentChunk($fp, $head, $tail, 1);
        }
        $lasttail = $tail;

        // add a possible revision of external edit, create or deletion
        if ($lasttail == $eof && $aftercount <= intval($max / 2) &&
            count($revs) && !$this->isCurrentRevision($revs[count($revs)-1])
        ) {
            $revs[] = $this->currentRevision;
            $aftercount++;
        }

        if ($aftercount == 0) {
            //given timestamp $rev is newer than the most recent line in chunk
            return false; //FIXME: or proceed to collect older revisions?
        }

        //read more chunks backward until $max/2 is reached and total number of revs is equal to $max
        $lines = array();
        $i = 0;
        if ($aftercount > 0) {
            $head = $starthead;
            $tail = $starttail;
            while ($head > 0) {
                list($lines, $head, $tail) = $this->readAdjacentChunk($fp, $head, $tail, -1);

                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $info = $this->parseLogLine($lines[$i]);
                    if ($this->cacheRevisionInfo($info)) {
                        $revs[] = $info['date'];
                        $beforecount++;
                        //enough revs before reference $rev?
                        if ($beforecount > max(intval($max / 2), $max - $aftercount)) break 2;
                    }
                }
            }
        }
        //keep only non-parsed lines
        $lines = array_slice($lines, 0, $i);

        sort($revs);

        //trunk desired selection
        $requestedrevs = array_slice($revs, -$max, $max);

        return array($requestedrevs, $revs, $fp, $lines, $head, $lasttail);
    }

    /**
     * Get the current revision information, considering external edit, create or deletion
     *
     * When the file has not modified since its last revision, the infomation of the last
     * change that had already recorded in the changelog is returned as current change info.
     * Otherwise, the change infomation since the last revision caused outside DokuWiki
     * should be returned, which is referred as "external revision".
     *
     * The change date of the file can be determined by timestamp as far as the file exists,
     * however this is not possible when the file has already deleted outside of DokuWiki.
     * In such case we assign 1 sec before current time() for the external deletion.
     * As a result, the value of current revision identifier may change each time because:
     *   1) the file has again modified outside of DokuWiki, or
     *   2) the value is essentially volatile for deleted but once existed files.
     *
     * @return bool|array false when page had never existed or array with entries:
     *      - date:  revision identifier (timestamp or last revision +1)
     *      - ip:    IPv4 address (127.0.0.1)
     *      - type:  log line type
     *      - id:    id of page or media
     *      - user:  user name
     *      - sum:   edit summary (or action reason)
     *      - extra: extra data (varies by line type)
     *      - sizechange: change of filesize
     *      - timestamp: unix timestamp or false (key set only for external edit occurred)
     *
     * @author  Satoshi Sahara <sahara.satoshi@gmail.com>
     */
    public function getCurrentRevisionInfo()
    {
        global $lang;

        if (isset($this->currentRevision)) return $this->getRevisionInfo($this->currentRevision);

        // get revision id from the item file timestamp and chagelog
        $fileLastMod = $this->getFilename();
        $fileRev = @filemtime($fileLastMod); // false when the file not exist
        $lastRev = $this->lastRevision();    // false when no changelog

        if (!$fileRev && !$lastRev) {                // has never existed
            $this->currentRevision = false;
            return false;
        } elseif ($fileRev === $lastRev) {           // not external edit
            $this->currentRevision = $lastRev;
            return $this->getRevisionInfo($lastRev);
        }

        if (!$fileRev && $lastRev) {                 // item file does not exist
            // check consistency against changelog
            $revInfo = $this->getRevisionInfo($lastRev, false);
            if ($revInfo['type'] == DOKU_CHANGE_TYPE_DELETE) {
                $this->currentRevision = $lastRev;
                return $revInfo;
            }

            // externally deleted, set revision date as late as possible
            $revInfo = [
                'date' => max($lastRev +1, time() -1), // 1 sec before now or new page save
                'ip'   => '127.0.0.1',
                'type' => DOKU_CHANGE_TYPE_DELETE,
                'id'   => $this->id,
                'user' => '',
                'sum'  => $lang['deleted'].' - '.$lang['external_edit'].' ('.$lang['unknowndate'].')',
                'extra' => '',
                'sizechange' => -io_getSizeFile($this->getFilename($lastRev)),
                'timestamp' => false,
            ];

        } else {                                     // item file exists, with timestamp $fileRev
            // here, file timestamp $fileRev is different with last revision timestamp $lastRev in changelog
            $isJustCreated = $lastRev === false || (
                    $fileRev > $lastRev &&
                    $this->getRevisionInfo($lastRev, false)['type'] == DOKU_CHANGE_TYPE_DELETE
            );
            $filesize_new = filesize($this->getFilename());
            $filesize_old = $isJustCreated ? 0 : io_getSizeFile($this->getFilename($lastRev));
            $sizechange = $filesize_new - $filesize_old;

            if ($isJustCreated) {
                $timestamp = $fileRev;
                $sum = $lang['created'].' - '.$lang['external_edit'];
            } elseif ($fileRev > $lastRev) {
                $timestamp = $fileRev;
                $sum = $lang['external_edit'];
            } else {
                // $fileRev is older than $lastRev, that is erroneous/incorrect occurence.
                $msg = "Warning: current file modification time is older than last revision date";
                $details = 'File revision: '.$fileRev.' '.strftime("%Y-%m-%d %H:%M:%S", $fileRev)."\n"
                          .'Last revision: '.$lastRev.' '.strftime("%Y-%m-%d %H:%M:%S", $lastRev);
                Logger::error($msg, $details, $this->getFilename());
                $timestamp = false;
                $sum = $lang['external_edit'].' ('.$lang['unknowndate'].')';
            }

            // externally created or edited
            $revInfo = [
                'date' => $timestamp ?: $lastRev +1,
                'ip'   => '127.0.0.1',
                'type' => $isJustCreated ? DOKU_CHANGE_TYPE_CREATE : DOKU_CHANGE_TYPE_EDIT,
                'id'   => $this->id,
                'user' => '',
                'sum'  => $sum,
                'extra' => '',
                'sizechange' => $sizechange,
                'timestamp' => $timestamp,
            ];
        }

        // cache current revision information of external edition
        $this->currentRevision = $revInfo['date'];
        $this->cache[$this->id][$this->currentRevision] = $revInfo;
        return $this->getRevisionInfo($this->currentRevision);
    }
}
