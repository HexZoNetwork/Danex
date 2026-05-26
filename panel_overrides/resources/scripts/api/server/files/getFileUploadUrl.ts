import http from '@/api/http';

export default (uuid: string): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/files/upload`)
            .then(({ data }) => {
                const url = data?.attributes?.url;
                if (typeof url !== 'string' || url.length === 0) {
                    return reject(new Error('Upload URL is unavailable.'));
                }

                resolve(url);
            })
            .catch(reject);
    });
};
