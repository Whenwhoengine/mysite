@if ($logs->isEmpty())
    <p class="empty">尚無稽核紀錄。</p>
@else
    <div class="table-wrap"><table class="table admin-audit">
        <thead><tr><th scope="col">時間</th><th scope="col">動作</th><th scope="col">操作者</th><th scope="col">對象</th><th scope="col">內容（已遮罩）</th><th scope="col">IP 前綴</th></tr></thead>
        <tbody>
        @foreach ($logs as $log)
            <tr>
                <td><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i:s') }}</time></td>
                <td><code>{{ $log->action }}</code></td>
                <td>{{ $log->actor_user_id ? '#'.$log->actor_user_id : $log->actor_type }}</td>
                <td>{{ $log->target_type ? $log->target_type.($log->target_id ? ' #'.$log->target_id : '') : '—' }}</td>
                <td><code class="admin-audit__meta">{{ $log->metadata ? json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</code></td>
                <td>{{ $log->ip_prefix ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
@endif
