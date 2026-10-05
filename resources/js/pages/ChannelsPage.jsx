import React, { useEffect, useState } from 'react';
import { api } from '../api';
import { useStores } from '../store-context';
import { CHANNEL_LABEL, ErrorBox, PublishBadge, TYPE_LABEL } from '../ui';

function ReceiptLog({ receipts }) {
    if (!receipts.length) return <p className="px-3 py-2 text-xs text-slate-400">暂无回执（外卖平台为异步回执，稍等片刻自动刷新）。</p>;
    return (
        <table className="w-full text-xs">
            <thead>
                <tr className="text-left text-slate-400">
                    <th className="px-3 py-1">时间</th>
                    <th className="px-3 py-1">第几次尝试</th>
                    <th className="px-3 py-1">结果</th>
                    <th className="px-3 py-1">是否采纳</th>
                    <th className="px-3 py-1">回执号 / 说明</th>
                </tr>
            </thead>
            <tbody>
                {receipts.map((r) => (
                    <tr key={r.id} className="border-t border-slate-100">
                        <td className="px-3 py-1">{r.created_at}</td>
                        <td className="px-3 py-1">#{r.attempt}</td>
                        <td className="px-3 py-1">{r.result === 'success' ? '成功' : '失败'}</td>
                        <td className="px-3 py-1">
                            {r.accepted
                                ? <span className="text-green-700">已采纳</span>
                                : <span className="font-medium text-amber-700">未采纳（迟到/已过期）</span>}
                        </td>
                        <td className="px-3 py-1 text-slate-500">
                            {r.payload?.receipt_no ?? '—'} {r.note ? `· ${r.note}` : ''}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function ChannelsPage() {
    const { store } = useStores();
    const [states, setStates] = useState([]);
    const [publishes, setPublishes] = useState([]);
    const [error, setError] = useState(null);
    const [expanded, setExpanded] = useState(null);

    const load = async () => {
        if (!store) return;
        try {
            const [st, pub] = await Promise.all([
                api.get('/api/channel-states'),
                api.get(`/api/channel-status?store_id=${store.id}`),
            ]);
            setStates(st);
            setPublishes(pub);
        } catch (e) {
            setError(e.message);
        }
    };

    useEffect(() => {
        load();
        const timer = setInterval(load, 5000); // 轮询等待异步回执
        return () => clearInterval(timer);
    }, [store?.id]);

    const retry = async (p) => {
        setError(null);
        try {
            await api.post(`/api/channel-publishes/${p.id}/retry`);
            load();
        } catch (e) {
            setError(e.message);
        }
    };

    const current = states.find((s) => s.store_id === store?.id);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="mb-1 text-xl font-bold">渠道状态 — {store?.name ?? ''}</h2>
                <p className="text-sm text-slate-500">上方为各渠道<strong>当前实际显示</strong>的内容；下方为每次发布的回执记录，失败渠道可单独重试。</p>
            </div>
            <ErrorBox error={error} onClose={() => setError(null)} />

            <div className="grid gap-3 sm:grid-cols-3">
                {(current?.channels ?? []).map((c) => (
                    <div key={c.channel} className="rounded-xl border border-slate-200 bg-white p-4">
                        <div className="text-sm text-slate-500">{c.channel_name}</div>
                        <div className="mt-1 font-semibold">{c.displaying?.label ?? '—'}</div>
                        <div className="mt-1 text-xs text-slate-400">
                            {c.adjustment_version ? `来自调整 v${c.adjustment_version} · ` : ''}
                            {c.synced_at ? `同步于 ${c.synced_at}` : '尚未同步过'}
                        </div>
                    </div>
                ))}
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th className="px-3 py-2">调整版本</th>
                            <th className="px-3 py-2">发布类型</th>
                            <th className="px-3 py-2">渠道</th>
                            <th className="px-3 py-2">状态</th>
                            <th className="px-3 py-2">尝试次数</th>
                            <th className="px-3 py-2">最近成功时间</th>
                            <th className="px-3 py-2">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        {publishes.map((p) => (
                            <React.Fragment key={p.id}>
                                <tr className="border-t border-slate-100">
                                    <td className="px-3 py-2">
                                        调整 #{p.adjustment.id} v{p.adjustment.version} · {TYPE_LABEL[p.adjustment.type]}
                                    </td>
                                    <td className="px-3 py-2">{p.kind === 'apply' ? '发布调整' : '恢复常规'}</td>
                                    <td className="px-3 py-2">{CHANNEL_LABEL[p.channel] ?? p.channel}</td>
                                    <td className="px-3 py-2">
                                        <PublishBadge status={p.status} />
                                        {p.last_error && <div className="mt-0.5 text-xs text-red-500">{p.last_error}</div>}
                                    </td>
                                    <td className="px-3 py-2">{p.attempts}</td>
                                    <td className="px-3 py-2 text-xs text-slate-500">{p.published_at ?? '—'}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex gap-2">
                                            {p.status === 'failed' && (
                                                <button onClick={() => retry(p)} className="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700">
                                                    单独重试
                                                </button>
                                            )}
                                            <button
                                                onClick={() => setExpanded(expanded === p.id ? null : p.id)}
                                                className="rounded-lg border border-slate-300 px-3 py-1 text-xs"
                                            >
                                                回执({p.receipts.length})
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                {expanded === p.id && (
                                    <tr className="border-t border-slate-100 bg-slate-50">
                                        <td colSpan={7}><ReceiptLog receipts={p.receipts} /></td>
                                    </tr>
                                )}
                            </React.Fragment>
                        ))}
                        {publishes.length === 0 && (
                            <tr><td colSpan={7} className="px-3 py-6 text-center text-sm text-slate-400">暂无发布记录</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
