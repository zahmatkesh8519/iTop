/*
 * Copyright (C) 2013-2020 Combodo SARL
 *
 * This file is part of iTop.
 *
 * iTop is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * iTop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 */

;
$(function() {
	$.widget('itop.activity_new_entry_form',
		{
			// default options
			options:
				{
					target_caselog: null,
					target_type: null,
					text_input_id: '',
				},
			css_classes:
				{
					is_expanded: 'ibo-is-expanded',
					is_opened: 'ibo-is-opened',
					is_closed: 'ibo-is-closed',
					is_active: 'ibo-is-active',
					is_hidden: 'ibo-is-hidden',
				},
			js_selectors:
				{
					panel: '[data-role="ibo-activity-panel"]',
					toggler: '[data-role="ibo-activity-panel--body--add-caselog-entry--toggler"]',
					form: '[data-role="ibo-activity-new-entry-form"]',
					right_actions: '[data-role="ibo-activity-new-entry-form--action-buttons--right-actions"]',
					caselog_picker: '[data-role="ibo-popover-menu"]',
				},

			// the constructor
			_create: function () {
				let me = this;
				me._HideNewEntryForm();
				$(this.js_selectors.toggler).on('click', function(oEvent){
					me._ShowNewEntryForm();
				});
				$(this.js_selectors.panel).on('show-caselog-tab', function(oEvent, sTabType, sCaseLogAttCode){
					me.options.target_type = sTabType;
					if(sTabType === 'caselog')
					{
						me.options.target_caselog = sCaseLogAttCode;
					}
				});
				$(this.js_selectors.right_actions).on('submit', function(oEvent, sTargetType, sTargetCaseLog){
					sTargetType = (sTargetType !== undefined) ? sTargetType : me.options.target_type;
					sTargetCaseLog = (sTargetCaseLog !== undefined) ? sTargetCaseLog : me.options.target_caselog;
					if(sTargetType === 'caselog')
					{
						me._SubmitNewEntryToCaselog(CKEDITOR.instances[me.options.text_input_id].getData(), sTargetCaseLog)
					}
					else
					{
						$(this).children(me.js_selectors.caselog_picker).show();
					}
				});
				$(this.js_selectors.right_actions).on('cancel', function(oEvent){
					me._HideNewEntryForm();
				});
			},
			_ShowNewEntryForm: function () {
				$(this.js_selectors.form).show();
				$(this.js_selectors.toggler).hide();
			},
			_HideNewEntryForm: function () {
				$(this.js_selectors.form).hide();
				$(this.js_selectors.toggler).show();
			},
			_SubmitNewEntryToCaselog: function(sData, sCaselog)
			{
				alert('Submited '+ sData +' to ' + sCaselog + ' caselog');
			}
		});
});