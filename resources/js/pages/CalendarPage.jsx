import React, { useEffect, useState } from 'react';
import { api } from '../api';
import { useStores } from '../store-context';
import { ErrorBox, TYPE_LABEL } from '../ui';

const DAY_STYLE = {
    regular: 'border-slate-200 bg-white',
    closure: 'border-red-300 bg-red-50',
    extended: 'border-indigo-300 bg-indigo-50',
    modified: 'border-indigo-300 bg-indigo-50',
};

const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

export default function CalendarPage() {
    const { store } = useStores();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!store) return;
        const from = new Date();
        const to = new Date(Date.now() + 13 * 86400000);
        const fmt = (d) => d.toISOString().slice(0, 10);
        api.get(`/api/stores/${store.id}/calendar?from=${fmt(from)}&to=${fmt(to)}`)
            .then(setData)
            .catch((e) => setError(e.message));
    }, [store?.id]);

    if (!store) return <p className="text-sm text-slate-500">加载门店中…</p>;

    const pendingOn = (date) =>
        (data?.pending_drafts ?? []).filter((p) => p.starts_at.slice(0, 10) <= date && p.ends_at.slice(0, 10) >= date);

    return (
        <div>
            <h2 className="mb-1 text-xl font-bold">门店日历 — {store.name}</h2>
            <p className="mb-4 text-sm text-slate-500">
                下表为<strong>当前实际对外生效</strong>的营业时间（仅含已批准并发布的调整）。
                审批中的草稿以虚线角标单独提示，不会当作已发布内容。
            </p>
            <ErrorBox error={error} onClose={() => setError(null)} />
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                {(data?.days ?? []).map((d) => {
                    const drafts = pendingOn(d.date);
                    const weekday = WEEKDAYS[new Date(`${d.date}T00:00:00`).getDay()];
                    return (
                        <div key={d.date} className={`relative rounded-xl border p-3 ${DAY_STYLE[d.type]}`}>
                            <div className="text-xs text-slate-500">{d.date} 周{weekday}</div>
                            <div className="mt-1 text-sm font-semibold">
                                {d.type === 'closure' ? '临时停业' : `${d.open} - ${d.close}`}
                            </div>
                            <div className="mt-0.5 text-xs text-slate-500">
                                {TYPE_LABEL[d.type]}
                                {d.version ? ` · v${d.version}` : ''}
                            </div>
                            {drafts.map((p) => (
                                <div
                                    key={p.id}
                                    title={`审批中 v${p.version}：${TYPE_LABEL[p.type]}`}
                                    className="mt-1 rounded border border-dashed border-amber-400 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700"
                                >
                                    审批中 v{p.version} · {TYPE_LABEL[p.type]}
                                </div>
                            ))}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
