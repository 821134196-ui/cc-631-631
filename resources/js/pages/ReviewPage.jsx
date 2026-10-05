import React, { useEffect, useState } from 'react';
import { api } from '../api';
import { useStores } from '../store-context';
import { AdjustmentBadge, ErrorBox, PublishBadge, TYPE_LABEL, fmtRange, hoursText } from '../ui';

export default function ReviewPage() {
    const { stores } = useStores();
    const [pending, setPending] = useState([]);
    const [error, setError] = useState(null);
    const [reviewer, setReviewer] = useState('总部运营');
    const [lastResult, setLastResult] = useState(null);

    const load = async () => {
        try {
            const all = await Promise.all(stores.map((s) => api.get(`/api/stores/${s.id}/adjustments`)));
            const rows = [];
            all.forEach((groups, i) => {
                groups.forEach((g) => {
                    const latest = g.versions[g.versions.length - 1];
                    if (latest?.status === 'pending') {
                        rows.push({ ...latest, store_name: stores[i].name, total_versions: g.versions.length });
                    }
                });
            });
            setPending(rows);
        } catch (e) {
            setError(e.message);
        }
    };

    useEffect(() => { load(); }, [stores]);

    const act = async (adj, action) => {
        setError(null);
        try {
            const result = await api.post(`/api/adjustments/${adj.id}/${action}`, { reviewer });
            setLastResult(action === 'approve' ? result : null);
            load();
        } catch (e) {
            setError(e.message);
        }
    };

    return (
        <div>
            <h2 className="mb-1 text-xl font-bold">总部审核</h2>
            <p className="mb-4 text-sm text-slate-500">批准后系统立即向官网、店内屏、外卖平台分别发布；各渠道结果见「渠道状态」页。</p>
            <ErrorBox error={error} onClose={() => setError(null)} />
            <label className="mb-4 block text-sm">
                审核人
                <input className="ml-2 rounded-lg border border-slate-300 px-2 py-1" value={reviewer} onChange={(e) => setReviewer(e.target.value)} />
            </label>

            {lastResult?.publishes && (
                <div className="mb-4 rounded-xl border border-green-300 bg-green-50 p-4 text-sm">
                    <p className="font-medium text-green-800">已批准 v{lastResult.version}，各渠道发布结果：</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {lastResult.publishes.map((p) => (
                            <span key={p.id} className="flex items-center gap-1 rounded-lg bg-white px-2 py-1 text-xs shadow-sm">
                                {p.channel === 'website' ? '官网' : p.channel === 'screen' ? '店内屏' : '外卖平台'}
                                <PublishBadge status={p.status} />
                            </span>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-green-700">失败或发布中的渠道可在「渠道状态」页单独重试 / 等待回执。</p>
                </div>
            )}

            {pending.length === 0 && <p className="text-sm text-slate-500">没有待审批的申请。</p>}
            <div className="space-y-3">
                {pending.map((a) => (
                    <div key={a.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4">
                        <AdjustmentBadge status={a.status} />
                        <div>
                            <div className="font-medium">
                                {a.store_name} · {TYPE_LABEL[a.type]}
                                <span className="ml-2 text-sm text-slate-500">v{a.version}{a.total_versions > 1 ? `（第 ${a.total_versions} 版申请）` : ''}</span>
                            </div>
                            <div className="text-sm text-slate-500">{fmtRange(a)} · {hoursText(a)} · {a.reason || '无原因'} · 提交人 {a.submitted_by || '—'}</div>
                        </div>
                        <div className="ml-auto flex gap-2">
                            <button onClick={() => act(a, 'approve')} className="rounded-lg bg-green-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-700">批准</button>
                            <button onClick={() => act(a, 'reject')} className="rounded-lg border border-red-300 px-4 py-1.5 text-sm text-red-600 hover:bg-red-50">驳回</button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
