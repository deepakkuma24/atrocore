/*
 * This file is part of EspoCRM and/or AtroCore.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * AtroCore is EspoCRM-based Open Source application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 *
 * AtroCore as well as EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroCore as well as EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word
 * and "AtroCore" word.
 */

Espo.define('views/fields/link-multiple', 'views/fields/base', function (Dep) {

    return Dep.extend({

        type: 'linkMultiple',

        listTemplate: 'fields/link-multiple/list',

        detailTemplate: 'fields/link-multiple/detail',

        editTemplate: 'fields/link-multiple/edit',

        searchTemplate: 'fields/link-multiple/search',

        nameHashName: null,

        idsName: null,

        nameHash: null,

        foreignScope: null,

        AUTOCOMPLETE_RESULT_MAX_COUNT: 7,

        autocompleteDisabled: false,

        selectRecordsView: 'views/modals/select-records',

        createDisabled: false,

        sortable: false,

        linkMultiple: true,

        searchTypeList: ['anyOf', 'isEmpty', 'isNotEmpty', 'noneOf'],

        data: function () {
            let ids = this.model.get(this.idsName);
            let nameHash = this.model.get(this.nameHashName);

            let foreignName = this.getMetadata().get(['entityDefs', this.model.urlRoot, 'fields', this.name, 'foreignName']);
            if (foreignName && foreignName !== 'name') {
                let collection = this.model.get(this.name);
                if (collection) {
                    this.nameHash = {};
                    collection.forEach(e => {
                        this.nameHash[e.id] = e[foreignName];
                    });
                    nameHash = this.nameHash;
                }
            }

            return _.extend({
                idValues: this.model.get(this.idsName),
                idValuesString: ids ? ids.join(',') : '',
                nameHash: nameHash,
                foreignScope: this.foreignScope,
                valueIsSet: this.model.has(this.idsName)
            }, Dep.prototype.data.call(this));
        },

        getSelectFilters: function () {},

        getSelectBoolFilterList: function () {
            return this.selectBoolFilterList;
        },

        getSelectPrimaryFilterName: function () {
            return this.selectPrimaryFilterName;
        },

        getCreateAttributes: function () {},

        setup: function () {
            this.nameHashName = this.name + 'Names';
            this.idsName = this.name + 'Ids';

            this.foreignScope = this.options.foreignScope || this.foreignScope || this.model.getFieldParam(this.name, 'entity') || this.model.getLinkParam(this.name, 'entity');

            if ('createDisabled' in this.options) {
                this.createDisabled = this.options.createDisabled;
            }

            var self = this;

            this.ids = Espo.Utils.clone(this.model.get(this.idsName) || []);
            this.nameHash = Espo.Utils.clone(this.model.get(this.nameHashName) || {});

            if (this.mode == 'search') {
                this.nameHash = Espo.Utils.clone(this.searchParams.nameHash) || {};
                this.ids = Espo.Utils.clone(this.searchParams.value) || [];
            }

            this.listenTo(this.model, 'change:' + this.idsName, function () {
                this.ids = Espo.Utils.clone(this.model.get(this.idsName) || []);
                this.nameHash = Espo.Utils.clone(this.model.get(this.nameHashName) || {});
            }, this);

            this.sortable = this.sortable || this.params.sortable;

            this.iconHtml = this.getHelper().getScopeColorIconHtml(this.foreignScope);

            if (this.mode != 'list') {
                this.addActionHandler('selectLink', function () {
                    self.notify('Loading...');

                    var viewName = this.getMetadata().get('clientDefs.' + this.foreignScope + '.modalViews.select')  || this.selectRecordsView;

                    this.createView('dialog', viewName, {
                        scope: this.foreignScope,
                        createButton: !this.createDisabled && this.mode != 'search',
                        filters: this.getSelectFilters(),
                        boolFilterList: this.getSelectBoolFilterList(),
                        primaryFilterName: this.getSelectPrimaryFilterName(),
                        multiple: this.linkMultiple,
                        massRelateEnabled: true,
                        createAttributes: (this.mode === 'edit') ? this.getCreateAttributes() : null,
                        mandatorySelectAttributeList: this.mandatorySelectAttributeList,
                        forceSelectAllAttributes: this.forceSelectAllAttributes
                    }, function (dialog) {
                        dialog.render();
                        self.notify(false);
                        this.listenToOnce(dialog, 'select', function (models) {
                            this.clearView('dialog');
                            if (models.massRelate) {
                                this.addLinkSubQuery(models);
                                return;
                            }
                            if (Object.prototype.toString.call(models) !== '[object Array]') {
                                models = [models];
                            }
                            models.forEach(function (model) {
                                if (typeof model.get !== "undefined") {
                                    self.addLink(model.id, model.get('name'));
                                } else if (model.name) {
                                    self.addLink(model.id, model.name);
                                } else {
                                    self.addLink(model.id, model.id);
                                }
                            });
                        });
                    }, this);
                });

                this.events['click a[data-action="clearLink"]'] = function (e) {
                    var id = $(e.currentTarget).attr('data-id');
                    this.deleteLink(id);
                };
                this.events['click a[data-action="clearLinkSubQuery"]'] = function (e) {
                    this.deleteLinkSubQuery();
                };
            }
        },

        handleSearchType: function (type) {
            if (~['anyOf', 'noneOf'].indexOf(type)) {
                this.$el.find('div.link-group-container').removeClass('hidden');
            } else {
                this.$el.find('div.link-group-container').addClass('hidden');
            }
        },

        setupSearch: function () {
            this.searchData.subQuery = this.searchParams.subQuery || [];
            this.events = _.extend({
                'change select.search-type': function (e) {
                    var type = $(e.currentTarget).val();
                    this.handleSearchType(type);
                },
            }, this.events || {});
        },

        getAutocompleteUrl: function () {
            var url = this.foreignScope + '?sortBy=name&maxCount=' + this.AUTOCOMPLETE_RESULT_MAX_COUNT;
            var boolList = this.getSelectBoolFilterList();
            var where = [];
            if (boolList) {
                url += '&' + $.param({'boolFilterList': boolList});
            }
            var primary = this.getSelectPrimaryFilterName();
            if (primary) {
                url += '&' + $.param({'primaryFilter': primary});
            }
            return url;
        },

        afterRender: function () {
            if (this.mode == 'edit' || this.mode == 'search') {
                this.$element = this.$el.find('input.main-element');

                var $element = this.$element;

                if (!this.autocompleteDisabled) {
                    this.$element.autocomplete({
                        serviceUrl: function (q) {
                            return this.getAutocompleteUrl(q);
                        }.bind(this),
                        minChars: 1,
                        paramName: 'q',
                        formatResult: function (suggestion) {
                            return suggestion.name;
                        },
                        transformResult: function (response) {
                            var response = JSON.parse(response);
                            var list = [];
                            response.list.forEach(function(item) {
                                list.push({
                                    id: item.id,
                                    name: item.name,
                                    data: item.id,
                                    value: item.name
                                });
                            }, this);
                            return {
                                suggestions: list
                            };
                        }.bind(this),
                        onSelect: function (s) {
                            this.addLink(s.id, s.name);
                            this.$element.val('');
                        }.bind(this)
                    });


                    this.once('render', function () {
                        $element.autocomplete('dispose');
                    }, this);

                    this.once('remove', function () {
                        $element.autocomplete('dispose');
                    }, this);
                }

                $element.on('change', function () {
                    $element.val('');
                });

                this.renderLinks();

                if (this.mode == 'edit') {
                    if (this.sortable) {
                        this.$el.find('.link-container').sortable({
                            stop: function () {
                                this.fetchFromDom();
                                this.trigger('change');
                            }.bind(this)
                        });
                    }
                }

                if (this.mode == 'search') {
                    this.addLinkSubQueryHtml(this.searchData.subQuery);
                    var type = this.$el.find('select.search-type').val();
                    this.handleSearchType(type);
                }
            }
        },

        renderLinks: function () {
            this.ids.forEach(function (id) {
                this.addLinkHtml(id, this.nameHash[id]);
            }, this);
        },

        deleteLinkSubQuery: function () {
            this.deleteLinkSubQueryHtml();
            this.searchData.subQuery = [];
        },

        deleteLink: function (id) {
            this.deleteLinkHtml(id);

            var index = this.ids.indexOf(id);

            if (index > -1) {
                this.ids.splice(index, 1);
            }
            delete this.nameHash[id];
            this.afterDeleteLink(id);
            this.trigger('change');
        },

        addLinkSubQuery: function (data) {
            let subQuery = data.where ?? [];
            this.searchData.subQuery = subQuery;
            this.addLinkSubQueryHtml(subQuery);
        },

        addLink: function (id, name) {
            if (!~this.ids.indexOf(id)) {
                this.ids.push(id);
                this.nameHash[id] = name;
                this.addLinkHtml(id, name);
                this.afterAddLink(id);
            }
            this.trigger('change');
        },

        afterDeleteLink: function (id) {},

        afterAddLink: function (id) {},

        deleteLinkHtml: function (id) {
            this.$el.find('.link-' + id).remove();
        },

        deleteLinkSubQueryHtml: function () {
            this.$el.find('.link-container .link-subquery').remove();
        },

        addLinkSubQueryHtml: function (subQuery) {
            if (!subQuery || subQuery.length === 0){
                return;
            }

            this.deleteLinkSubQueryHtml();

            var $container = this.$el.find('.link-container');
            var $el = $('<div />').addClass('link-subquery').addClass('list-group-item');
            $el.html('(Subquery) &nbsp');
            $el.prepend('<a href="javascript:" class="pull-right" data-action="clearLinkSubQuery"><span class="fas fa-times"></a>');
            $container.append($el);

            return $el;
        },

        addLinkHtml: function (id, name) {
            var $container = this.$el.find('.link-container');
            var $el = $('<div />').addClass('link-' + id).addClass('list-group-item').attr('data-id', id);
            $el.html(this.getHelper().stripTags(name || id) + '&nbsp');
            $el.prepend('<a href="javascript:" class="pull-right" data-id="' + id + '" data-action="clearLink"><span class="fas fa-times"></a>');
            $container.append($el);

            return $el;
        },

        getIconHtml: function (id) {
            return this.iconHtml;
        },

        getDetailLinkHtml: function (id) {
            var name = this.nameHash[id] || id;
            if (!name && id) {
                name = this.translate(this.foreignScope, 'scopeNames');
            }
            var iconHtml = '';
            if (this.mode == 'detail') {
                iconHtml = this.getIconHtml(id);
            }
            return '<a href="#' + this.foreignScope + '/view/' + id + '">' + iconHtml + this.getHelper().stripTags(name) + '</a>';
        },

        getValueForDisplay: function () {
            if (this.mode == 'detail' || this.mode == 'list') {
                var names = [];
                this.ids.forEach(function (id) {
                    names.push(this.getDetailLinkHtml(id));
                }, this);
                if (names.length) {
                    return '<div>' + names.join('</div><div>') + '</div>';
                }
                return;
            }
        },

        validateRequired: function () {
            if (this.isRequired()) {
                var idList = this.model.get(this.idsName) || [];
                if (idList.length == 0) {
                    var msg = this.translate('fieldIsRequired', 'messages').replace('{field}', this.getLabelText());
                    this.showValidationMessage(msg);
                    return true;
                }
            }
        },

        fetch: function () {
            var data = {};
            data[this.idsName] = this.ids;
            data[this.nameHashName] = this.nameHash;

            return data;
        },

        fetchFromDom: function () {
            this.ids = [];
            this.$el.find('.link-container').children().each(function(i, li) {
                var id = $(li).attr('data-id');
                if (!id) return;
                this.ids.push(id);
            }.bind(this));
        },

        clearSearch: function () {
            this.ids = [];

            this.reRender();
        },

        fetchSearch: function () {
            var type = this.$el.find('select.search-type').val();

            if (type === 'anyOf') {
                var idList = this.ids || [];

                var data = {
                    type: 'linkedWith',
                    value: idList,
                    nameHash: this.nameHash,
                    subQuery: this.searchData.subQuery,
                    data: {
                        type: type
                    }
                };
                if (!idList.length) {
                    data.value = null;
                }
                return data;
            } else if (type === 'noneOf') {
                var values = this.ids || [];

                var data = {
                    type: 'notLinkedWith',
                    value: this.ids || [],
                    nameHash: this.nameHash,
                    subQuery: this.searchData.subQuery,
                    data: {
                        type: type
                    }
                };
                return data;
            } else if (type === 'isEmpty') {
                var data = {
                    type: 'isNotLinked',
                    data: {
                        type: type
                    }
                };
                return data;
            } else if (type === 'isNotEmpty') {
                var data = {
                    type: 'isLinked',
                    data: {
                        type: type
                    }
                };
                return data;
            }
        },

        getSearchType: function () {
            return this.getSearchParamsData().type || this.searchParams.typeFront || this.searchParams.type || 'anyOf';
        }

    });
});


