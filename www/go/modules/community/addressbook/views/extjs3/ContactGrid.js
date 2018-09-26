go.modules.community.addressbook.ContactGrid = Ext.extend(go.grid.GridPanel, {
	cls: 'x-grid3-no-row-borders',
	initComponent: function () {

		this.store = new go.data.Store({
			fields: [
				'id',
				'name',
				{name: 'createdAt', type: 'date'},
				{name: 'modifiedAt', type: 'date'},
				{name: 'creator', type: go.data.types.User, key: 'createdBy'},
				{name: 'modifier', type: go.data.types.User, key: 'modifiedBy'},
				{name: 'star', type: go.data.types.ContactStar, key: function(r) {return r.id + "-" + go.User.id}},
				'permissionLevel',
				'photoBlobId',
				"isOrganization",
				{name: 'organizations', type: go.data.types.Contact, key: 'organizations.organizationContactId'},			
			].concat(go.modules.core.customfields.CustomFields.getFieldDefinitions("Contact")),
			sortInfo :{field: "name", direction: "ASC"},
			entityStore: go.Stores.get("Contact")
		});
		
//		//reload store when user changes stars
		go.Stores.get("ContactStar").on("changes", this.onStarChanges, this);

		var grid = this;

		Ext.apply(this, {

			columns: [
				{
					width: dp(48),
					id: "index",
					dataIndex: "star",
					sortable: false,
					draggable: false,
					hideable: false,
					renderer: function (value, metaData, record, rowIndex, colIndex, store) {
						if(rowIndex == 0 && value && value.starred) {							
							return '<div class="icon ic-star go-addressbook-star"></div>';
						} else
						{
							if(value && value.starred) {
								return "";
							}
							
							var lastRecord = rowIndex > 0 ? grid.store.getAt(rowIndex - 1) : false;
							var char = record.data.name.substr(0, 1);
							if(!lastRecord || lastRecord.data.name.substr(0, 1) != char) {
								return "<h3>" + char.toUpperCase() + "</h3>";
							}
						}
						
						return "";
					}
				},
				{
					id: 'id',
					hidden: true,
					header: 'ID',
					width: dp(40),
					sortable: true,
					dataIndex: 'id'
				},
				{
					id: 'name',
					header: t('Name'),
					sortable: true,
					dataIndex: 'name',
					renderer: function (value, metaData, record, rowIndex, colIndex, store) {


						var style = "", cls = "";

						if (record.data.photoBlobId) {
							style = 'background-image: url(' + go.Jmap.downloadUrl(record.data.photoBlobId) + ')"';
						} else
						{
							cls = record.data.isOrganization ? "organization" : "";
						}

						return '<div class="user"><div class="avatar ' + cls + '" style="' + style + '"></div>' +
										'<div class="wrap single">' + record.get('name') + '</div>' +
										'</div>';
					}
				},
				{
					id: 'organizations',
					header: t('Organizations'),
					sortable: false,
					dataIndex: "organizations",
					width: dp(300),
					renderer: function (organizations, meta, record) {
						return organizations.column("name").join(", ");
					}
				},
				{
					xtype: "datecolumn",
					id: 'createdAt',
					header: t('Created at'),
					width: dp(160),
					sortable: true,
					dataIndex: 'createdAt',
					hidden: true
				},
				{
					xtype: "datecolumn",
					hidden: false,
					id: 'modifiedAt',
					header: t('Modified at'),
					width: dp(160),
					sortable: true,
					dataIndex: 'modifiedAt',
					hidden: true
				},
				{
					hidden: true,
					header: t('Created by'),
					width: dp(160),
					sortable: true,
					dataIndex: 'creator',
					renderer: function (v) {
						return v ? v.displayName : "-";
					}
				},
				{
					hidden: true,
					header: t('Modified by'),
					width: dp(160),
					sortable: true,
					dataIndex: 'modifier',
					renderer: function (v) {
						return v ? v.displayName : "-";
					}
				}
			].concat(go.modules.core.customfields.CustomFields.getColumns("Contact")),
			viewConfig: {
				emptyText: '<i>description</i><p>' + t("No items to display") + '</p>',
//				enableRowBody: true,
//				showPreview: true,
				getRowClass: function (record, rowIndex, p, store) {					
					var value = record.get("star");
					if(rowIndex == 0 && value && value.starred) {							
						return '';
					} else
					{
						if(value && value.starred) {
							return "";
						}

						var lastRecord = rowIndex > 0 ? grid.store.getAt(rowIndex - 1) : false;
						var char = record.data.name.substr(0, 1);
						if(!lastRecord || lastRecord.data.name.substr(0, 1) != char) {
							return 'go-addressbook-index-row';
						}
						return "";
					}
				}
			},
			autoExpandColumn: 'name',
			// config options for stateful behavior
//			stateful: true,
//			stateId: 'contact-grid'
		});
		

		go.modules.community.addressbook.ContactGrid.superclass.initComponent.call(this);
	},
	
	onStarChanges: function(store, added, changed, destroyed) {
		
		if(!this.store.loaded) {
			return;
		}
		
		//changes should always reload list
		if(changed.length) {
			this.store.reload();
		}
		
		if(!added.length) {
			return;
		}
		
		go.Stores.get("ContactStar").get(added, function(stars) {			
			var starContactIds = stars.column("contactId")
			var storeContactIds = this.store.getRange().column('id');
			
			//if there are new stars not present in the store then reload.
			if(starContactIds.diff(storeContactIds).length) {				
				this.store.reload();
			}
		}, this);

	},

	//when filtering on a group then offer to delete contacts from a group when delting.
	deleteSelected: function () {
		if (!this.store.baseParams.filter.groupId) {
			return go.grid.GridTrait.deleteSelected.call(this);
		}

		var groupId = this.store.baseParams.filter.groupId;

		var selectedRecords = this.getSelectionModel().getSelections(), ids = selectedRecords.column('id'), strConfirm;

		switch (ids.length)
		{
			case 0:
				return;
			case 1:
				strConfirm = t("Are you sure you want to delete the selected item?");
				break;

			default:
				strConfirm = t("Are you sure you want to delete the {count} items?").replace('{count}', ids.length);
				break;
		}

		Ext.Msg.show({
			title: t("Confirm delete"),
			msg: t(strConfirm),
			buttons: {ok: t("Remove from group"), yes: t("Delete"), "cancel": t("Cancel")},
			fn: function (btn) {

				if (btn == "yes") {
					this.getStore().entityStore.set({
						destroy: ids
					});
				}

				if (btn == "ok") {
					var updates = {};


					selectedRecords.forEach(function (r) {
						var groupIndex = r.json.groups.column("groupId").indexOf(groupId);
//							console.log(groupIndex, groupId, r.json.groups);
						updates[r.id] = {
							groups: GO.util.clone(r.json.groups)
						};
						updates[r.id].groups.splice(groupIndex, 1);
					});

					this.getStore().remove(selectedRecords);

					this.getStore().entityStore.set({
						update: updates
					});
				}
			},
			scope: this,
			icon: Ext.MessageBox.QUESTION
		});
	}
});

