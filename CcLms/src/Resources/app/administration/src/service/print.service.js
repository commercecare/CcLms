import ApiService from 'src/core/service/api.service';

/**
 * Gateway for the API end point "cc_lms"
 * @class
 * @extends ApiService
 */
export default class PrintService extends ApiService {
    constructor (httpClient, loginService, apiEndpoint = 'cc_lms') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'ccLmsPrintService';
    }

    print (action, ids, additionalParams = {}, additionalHeaders = {}) {
        const headers = this.getBasicHeaders(additionalHeaders);
        let params = { action, ids };

        params = Object.assign(params, additionalParams);

        return this.httpClient
            .get(`/_action/${this.getApiBasePath()}/print`, {
                ...{
                    responseType: "blob"
                },
                params: params,
                headers
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
};