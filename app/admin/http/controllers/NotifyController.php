<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\http\concerns\ApiResponse;

/**
 * 通知中心：我的通知 / 未读数 / 已读 / 全部已读 / 清理 + 超管广播。
 */
final class NotifyController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $uid = (int) (Request::attr('auth')->uid ?? 0);
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
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
            [$uid, $size, ($page - 1) * $size]
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
        $uid = (int) (Request::attr('auth')->uid ?? 0);
        $count = (int) (Db::select(
            'SELECT COUNT(*) AS c FROM kode_notifications WHERE user_id IN (0, ?) AND is_read = 0',
            [$uid]
        )[0]['c'] ?? 0);

        return $this->ok(['unread' => $count]);
    }

    public function read()
    {
        $uid = (int) (Request::attr('auth')->uid ?? 0);
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
        $uid = (int) (Request::attr('auth')->uid ?? 0);
        Db::update(
            'UPDATE kode_notifications SET is_read = 1, read_at = NOW() WHERE user_id IN (0, ?) AND is_read = 0',
            [$uid]
        );

        return $this->ok(['updated' => true]);
    }

    public function clear()
    {
        $uid = (int) (Request::attr('auth')->uid ?? 0);
        $deleted = Db::delete(
            'DELETE FROM kode_notifications WHERE user_id IN (0, ?) AND is_read = 1',
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
