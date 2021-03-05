import template from './sw-order-list.html.twig';
import './sw-order-list.scss';

const { Component, Mixin } = Shopware;

Component.override('sw-order-list', {
    template,

    inject: [
        'ccLmsPrintService'
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        printAction: {
            type: String,
            required: false,
            default: null
        },
    },

    data () {
        return {
            isPrintSuccessful: false,
            printActions: [
                {
                    label: this.$tc('cc_lms.sw-order.list.print-action.options.invoice'),
                    value: 'invoice'
                }, {
                    label: this.$tc('cc_lms.sw-order.list.print-action.options.delivery'),
                    value: 'delivery'
                },
                {
                    label: this.$tc('cc_lms.sw-order.list.print-action.options.dhl'),
                    value: 'dhl'
                }
            ]
        };
    },

    computed: {
        selectedOrdersIds () {
            return Object.keys(this.selection);
        }
    },

    methods: {
        printFinish () {
            this.isPrintSuccessful = false;
        },

        async onPrint () {
            this.isLoading = true;
            this.isPrintSuccessful = false;

            return this.ccLmsPrintService.print(this.printAction, this.selectedOrdersIds).then((blob) => {
                this.isLoading = false;
                this.isPrintSuccessful = true;
                this.createdComponent();
                if (blob && blob.size) {
                    let a = document.createElement('a');
                    a.href = window.URL.createObjectURL(blob);
                    a.download = this.printAction + '.zip';
                    a.click();

                    this.createNotificationSuccess({
                        title: this.$tc('global.default.success'),
                        message: this.$tc('cc_lms.sw-order.list.messagePrintSuccess')
                    });
                    return;
                }
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('cc_lms.sw-order.list.messagePrintError'),
                    autoClose: false
                });
            }).catch((exception) => {
                this.isLoading = false;
            });
        }
    }
});
