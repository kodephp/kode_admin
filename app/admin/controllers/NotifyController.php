<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\support\AccountScope;

/**
 * 通知中心：我的通知 / 未读数 / 已读 / 全部已读 / 清理 + 超管广播。
 *
 * 广播行（user_id=0）在库里是**一条全站共享记录**，不是每人一份副本：
 * 所以 read / read-all 会把它对全站一并翻成已读 —— 当作「公告已周知」的语义，
 * 认了就认了。但 clear 绝不能碰它：那等于任何人一次「清空已读」替全站删掉公告
 * （不可逆、跨账号），故清理只限本人行。
 * 将来若要做「每人各自是否读过公告」，得加一张 (notification_id, user_id) 已读表，
 * 而不是继续共用同一列。
 */
final class NotifyController extends Controller
{
    use ApiResponse;
    use PageWindow;

    public function index()
    {
        // uid 为 0 时本表的 user_id=0（全员广播）会变成「我的」收件箱，先挡掉。
        $uid = AccountScope::uidOf(AccountScope::payload());
        if ($uid <= 0) {
            return $this->fail('未认证', 401);
        }
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $readRaw = $this->query('is_read', null);
        $read = $readRaw === null || $readRaw === '' ? null : ((int) $readRaw ? 1 : 0);
        $cond = 'user_id IN (0, ?)' . ($read === null ? '' : ' AND is_read = ' . $read);
        $total = (int) (Db::select(
            "SELECT COUNT(*) AS c FROM kode_notifications WHERE {$cond}",
            [$uid]
        )[0]['c'] ?? 0);
        $rows = Db::select(
            "SELECT id, type, title, content, level, url, is_read, created_at
             FROM kode_notifications WHERE {$cond} ORDER BY id DESC LIMIT ? OFFSET ?",
            [$uid, $size, $offset]
        );
        foreach ($rows as &$row) {
            $row['id'] = \app\support\HashId::enc((int) $row['id']);
            if ($row['created_at'] instanceof \DateTimeInterface) {
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function unreadCount()
    {
        // uid 为 0 时本表的 user_id=0（全员广播）会变成「我的」收件箱，先挡掉。
        $uid = AccountScope::uidOf(AccountScope::payload());
        if ($uid <= 0) {
            return $this->fail('未认证', 401);
        }
        $count = (int) (Db::select(
            'SELECT COUNT(*) AS c FROM kode_notifications WHERE user_id IN (0, ?) AND is_read = 0',
            [$uid]
        )[0]['c'] ?? 0);

        return $this->ok(['unread' => $count]);
    }

    public function read()
    {
        // uid 为 0 时本表的 user_id=0（全员广播）会变成「我的」收件箱，先挡掉。
        $uid = AccountScope::uidOf(AccountScope::payload());
        if ($uid <= 0) {
            return $this->fail('未认证', 401);
        }
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        Db::update(
            'UPDATE kode_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id IN (0, ?)',
            [$id, $uid]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    public function readAll()
    {
        // uid 为 0 时本表的 user_id=0（全员广播）会变成「我的」收件箱，先挡掉。
        $uid = AccountScope::uidOf(AccountScope::payload());
        if ($uid <= 0) {
            return $this->fail('未认证', 401);
        }
        Db::update(
            'UPDATE kode_notifications SET is_read = 1, read_at = NOW() WHERE user_id IN (0, ?) AND is_read = 0',
            [$uid]
        );

        return $this->ok(['updated' => true]);
    }

    public function clear()
    {
        // uid 为 0 时本表的 user_id=0（全员广播）会变成「我的」收件箱，先挡掉。
        $uid = AccountScope::uidOf(AccountScope::payload());
        if ($uid <= 0) {
            return $this->fail('未认证', 401);
        }
        // 只删本人的行：广播行全站共享一条记录，写成 IN (0, ?) 就等于
        // 任何一次「清空已读」替全站删掉公告（不可逆、跨账号）。
        $deleted = Db::delete(
            'DELETE FROM kode_notifications WHERE user_id = ? AND is_read = 1',
            [$uid]
        );

        return $this->ok(['deleted' => $deleted]);
    }

    /** 超管广播（user_id=0 全员可见）。 */
    public function broadcast()
    {
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:1|max:128',
            'content' => 'nullable|max:2000',
            'level' => 'nullable|max:16',
            'url' => 'nullable|max:255',
        ]);
        $id = Db::insert(
            'INSERT INTO kode_notifications (user_id, type, title, content, level, url, created_at) VALUES (0, ?, ?, ?, ?, ?, NOW())',
            ['broadcast', $data['title'], (string) ($data['content'] ?? ''), (string) ($data['level'] ?? 'info'), (string) ($data['url'] ?? '')]
        );

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }
}
