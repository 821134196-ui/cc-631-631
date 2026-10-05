import React from 'react';

export const ADJUSTMENT_STATUS = {
    pending: { label: '审批中', cls: 'bg-amber-100 text-amber-800 border-amber-300' },
    approved: { label: '已批准', cls: 'bg-green-100 text-green-800 border-green-300' },
    rejected: { label: '已驳回', cls: 'bg-red-100 text-red-700 border-red-300' },
    superseded: { label: '已被新版本取代', cls: 'bg-slate-200 text-slate-600 border-slate-300' },
    expired: { label: '已到期恢复', cls: 'bg-sky-100 text-sky-800 border-sky-300' },
};

export const PUBLISH_STATUS = {
    pending: { label: '发布中…', cls: 'bg-amber-100 text-amber-800' },
    success: { label: '同步成功', cls: 'bg-green-100 text-green-800' },
    failed: { label: '同步失败', cls: 'bg-red-100 text-red-700' },
    stale: { label: '已失效(被新版本取代)', cls: 'bg-slate-200 text-slate-500' },
};

export const TYPE_LABEL = {
    closure: '临时停业',
    extended: '延长营业',
    modified: '自定义时段',
    regular: '正常营业',
};

export const CHANNEL_LABEL = { website: '官网', screen: '店内屏', delivery: '外卖平台' };

export function Badge({ tone }) {
    return (
        <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${tone.cls}`}>
            {tone.label}
        </span>
    );
}

export function AdjustmentBadge({ status }) {
    return <Badge tone={ADJUSTMENT_STATUS[status] ?? { label: status, cls: 'bg-slate-100' }} />;
}

export function PublishBadge({ status }) {
    return <Badge tone={PUBLISH_STATUS[status] ?? { label: status, cls: 'bg-slate-100' }} />;
}

export function ErrorBox({ error, onClose }) {
    if (!error) return null;
    return (
        <div className="mb-4 flex items-start justify-between rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700">
            <span>{error}</span>
            {onClose && <button className="ml-4 font-bold" onClick={onClose}>×</button>}
        </div>
    );
}

export function fmtRange(a) {
    return `${a.starts_at?.slice(0, 16).replace('T', ' ')} ~ ${a.ends_at?.slice(0, 16).replace('T', ' ')}`;
}

export function hoursText(a) {
    if (a.type === 'closure') return '全天停业';
    return `${a.open_time ?? '?'} - ${a.close_time ?? '?'}`;
}
