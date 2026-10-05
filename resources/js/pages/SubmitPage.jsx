import React, { useEffect, useState } from 'react';
import { api } from '../api';
import { useStores } from '../store-context';
import { AdjustmentBadge, ErrorBox, TYPE_LABEL, fmtRange, hoursText } from '../ui';

const EMPTY = { type: 'closure', starts_at: '', ends_at: '', open_time: '', close_time: '', reason: '', submitted_by: '' };

function toLocalInput(dt) {
    return dt ? dt.replace(' ', 'T').slice(0, 16) : '';
}

function AdjustmentForm({ initial, onSubmit, onCancel, submitLabel }) {
    const [form, setForm] = useState(initial);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const payload = {
                ...form,
                starts_at: form.starts_at.replace('T', ' '),
                ends_at: form.ends_at.replace('T', ' '),
                open_time: form.type === 'closure' ? null : form.open_time,
                close_time: form.type === 'closure' ? null : form.close_time,
            };
            await onSubmit(payload);
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-4">
            <ErrorBox error={error} onClose={() => setError(null)} />
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm">
                    调整类型
                    <select className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.type} onChange={set('type')}>
                        <option value="closure">临时停业</option>
                        <option value="extended">延长营业</option>
                        <option value="modified">自定义时段</option>
                    </select>
                </label>
                <label className="text-sm">
                    提交人
                    <input className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.submitted_by} onChange={set('submitted_by')} placeholder="门店店长" />
                </label>
                <label className="text-sm">
                    开始时间
                    <input type="datetime-local" required className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.starts_at} onChange={set('starts_at')} />
                </label>
                <label className="text-sm">
                    结束时间
                    <input type="datetime-local" required className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.ends_at} onChange={set('ends_at')} />
                </label>
                {form.type !== 'closure' && (
                    <>
                        <label className="text-sm">
                            调整期间开门
                            <input type="time" required className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.open_time} onChange={set('open_time')} />
                        </label>
                        <label className="text-sm">
                            调整期间打烊
                            <input type="time" required className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.close_time} onChange={set('close_time')} />
                        </label>
                    </>
                )}
                <label className="text-sm sm:col-span-2">
                    原因
                    <input className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5" value={form.reason} onChange={set('reason')} placeholder="例如：设备检修 / 节假日延长营业" />
                </label>
            </div>
            <div className="mt-4 flex gap-2">
                <button disabled={busy} className="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    {busy ? '提交中…' : submitLabel}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-1.5 text-sm">取消</button>
                )}
            </div>
        </form>
    );
}

export default function SubmitPage() {
    const { store } = useStores();
    const [groups, setGroups] = useState([]);
    const [notice, setNotice] = useState(null);
    const [error, setError] = useState(null);
    const [revising, setRevising] = useState(null); // adjustment being revised

    const load = () => {
        if (!store) return;
        api.get(`/api/stores/${store.id}/adjustments`).then(setGroups).catch((e) => setError(e.message));
    };
    useEffect(load, [store?.id]);

    if (!store) return <p className="text-sm text-slate-500">加载门店中…</p>;

    // 每组最新且已批准的版本 = 可延期/提前结束的调整
    const active = groups
        .map((g) => g.versions[g.versions.length - 1])
        .filter((v) => v && v.status === 'approved');

    const submitNew = async (payload) => {
        await api.post(`/api/stores/${store.id}/adjustments`, payload);
        setNotice('已提交，等待总部审核。可在「总部审核」页查看审批进度。');
        load();
    };

    const submitRevision = async (payload) => {
        await api.post(`/api/adjustments/${revising.id}/revise`, payload);
        setNotice(`已基于 v${revising.version} 生成新版本 v${revising.version + 1}（待审批），原调整记录保留。`);
        setRevising(null);
        load();
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="mb-1 text-xl font-bold">提交调整 — {store.name}</h2>
                <p className="text-sm text-slate-500">一次申请，总部批准后自动同步官网、店内屏、外卖平台。</p>
            </div>
            {notice && <div className="rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-sm text-green-700">{notice}</div>}
            <ErrorBox error={error} onClose={() => setError(null)} />

            <AdjustmentForm initial={EMPTY} onSubmit={submitNew} submitLabel="提交申请" />

            <section>
                <h3 className="mb-2 font-semibold">生效中的调整（可延期 / 提前结束）</h3>
                {active.length === 0 && <p className="text-sm text-slate-500">当前没有生效中的调整。</p>}
                <div className="space-y-3">
                    {active.map((a) => (
                        <div key={a.id} className="rounded-xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="font-medium">v{a.version} · {TYPE_LABEL[a.type]}</span>
                                <AdjustmentBadge status={a.status} />
                                <span className="text-sm text-slate-500">{fmtRange(a)} · {hoursText(a)}</span>
                                <button
                                    className="ml-auto rounded-lg border border-indigo-300 px-3 py-1 text-sm text-indigo-700 hover:bg-indigo-50"
                                    onClick={() => setRevising(a)}
                                >
                                    延期 / 提前结束
                                </button>
                            </div>
                            {revising?.id === a.id && (
                                <div className="mt-4">
                                    <p className="mb-2 text-sm text-slate-500">
                                        将生成新版本 v{a.version + 1}，批准后取代 v{a.version}；原记录保留可查。
                                    </p>
                                    <AdjustmentForm
                                        initial={{
                                            type: a.type,
                                            starts_at: toLocalInput(a.starts_at),
                                            ends_at: toLocalInput(a.ends_at),
                                            open_time: a.open_time ?? '',
                                            close_time: a.close_time ?? '',
                                            reason: a.reason ?? '',
                                            submitted_by: a.submitted_by ?? '',
                                        }}
                                        onSubmit={submitRevision}
                                        onCancel={() => setRevising(null)}
                                        submitLabel={`生成新版本 v${a.version + 1}`}
                                    />
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
