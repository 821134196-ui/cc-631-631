async function request(method, url, body) {
    const res = await fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const err = new Error(data.message || `请求失败（${res.status}）`);
        err.validation = data.errors || null;
        throw err;
    }
    return data;
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body),
};

export const CHANNEL_LABELS = { website: '官网', screen: '店内屏', delivery: '外卖平台' };
export const CHANNELS = ['website', 'screen', 'delivery'];

export const ADJ_STATUS = {
    pending: { label: '审核中', cls: 'st-pending' },
    approved: { label: '已批准·生效中', cls: 'st-approved' },
    rejected: { label: '已驳回', cls: 'st-rejected' },
    superseded: { label: '已被新版本取代', cls: 'st-superseded' },
    expired: { label: '已到期恢复', cls: 'st-expired' },
};

export const RECEIPT_STATUS = {
    success: { label: '已同步', cls: 'rc-success' },
    failed: { label: '失败', cls: 'rc-failed' },
    pending: { label: '等待回执', cls: 'rc-pending' },
    stale_ignored: { label: '晚到已忽略', cls: 'rc-stale' },
};

export const TASK_STATUS = {
    pending: { label: '待执行', cls: 'st-pending' },
    done: { label: '已执行恢复', cls: 'st-approved' },
    cancelled_superseded: { label: '因延期被取消', cls: 'st-superseded' },
    skipped_stale: { label: '已失效·跳过', cls: 'st-expired' },
};

export function fmt(dt) {
    return dt ? dt.slice(0, 16) : '—';
}

export function fmtDate(dt) {
    return dt ? dt.slice(0, 10) : '—';
}
