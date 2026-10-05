import React, { useEffect, useState } from 'react';
import { api } from '../api';
import { useStores } from '../store-context';
import { AdjustmentBadge, ErrorBox, PublishBadge, TYPE_LABEL, fmtRange, hoursText } from '../ui';

export default function RecordsPage() {
    const { store } = useStores();
    const [groups, setGroups] = useState([]);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!store) return;
        api.get(`/api/stores/${store.id}/adjustments`).then(setGroups).catch((e) => setError(e.message));
    }, [store?.id]);

    return (
        <div>
            <h2 className="mb-1 text-xl font-bold">调整记录 — {store?.name ?? ''}</h2>
            <p className="mb-4 text-sm text-slate-500">
                每次延期/提前结束都会生成新版本，<strong>原版本完整保留</strong>。
                左侧徽标是<strong>批准结果</strong>；右侧小徽标是该版本在各渠道的<strong>同步状态</strong>，两者互不影响。
            </p>
            <ErrorBox error={error} onClose={() => setError(null)} />
            <div className="space-y-4">
                {groups.map((g) => (
                    <div key={g.group_id} className="rounded-xl border border-slate-200 bg-white p-4">
                        <div className="mb-2 text-xs text-slate-400">调整组 {g.group_id.slice(0, 8)}…（共 {g.versions.length} 版）</div>
                        <ol className="relative space-y-3 border-l border-slate-200 pl-4">
                            {g.versions.map((v) => (
                                <li key={v.id}>
                                    <div className="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-slate-300" />
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">v{v.version} · {TYPE_LABEL[v.type]}</span>
                                        <AdjustmentBadge status={v.status} />
                                        {(v.publishes ?? []).map((p) => (
                                            <span key={p.id} className="flex items-center gap-1 text-xs text-slate-500">
                                                {p.channel === 'website' ? '官网' : p.channel === 'screen' ? '店内屏' : '外卖平台'}
                                                {p.kind === 'restore' ? '(恢复)' : ''}
                                                <PublishBadge status={p.status} />
                                            </span>
                                        ))}
                                    </div>
                                    <div className="mt-0.5 text-sm text-slate-500">
                                        {fmtRange(v)} · {hoursText(v)} · {v.reason || '无原因'}
                                    </div>
                                    <div className="text-xs text-slate-400">
                                        提交 {v.submitted_by || '—'}
                                        {v.reviewed_at ? ` · ${v.reviewed_by} 于 ${v.reviewed_at} 审核` : ' · 待审核'}
                                        {v.supersedes_id ? ` · 取代 #${v.supersedes_id}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>
                ))}
                {groups.length === 0 && <p className="text-sm text-slate-500">暂无调整记录。</p>}
            </div>
        </div>
    );
}
