const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };

async function request(method, url, body) {
    const res = await fetch(url, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const message = data.message
            || (data.errors ? Object.values(data.errors).flat().join('；') : null)
            || `请求失败 (${res.status})`;
        throw new Error(message);
    }
    return data;
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body ?? {}),
};
