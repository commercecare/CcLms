import PrintService from '../service/print.service.js';

Shopware.Application.addServiceProvider('ccLmsPrintService', container => {
    return new PrintService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});