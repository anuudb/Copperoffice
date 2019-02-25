/* global Ext, go */

go.links.LinkGrid = Ext.extend(go.grid.GridPanel, {

	initComponent: function () {

		this.columns = [
			{
				id: 'name',
				header: t('Name'),
				width: dp(100),
				sortable: true,
				dataIndex: 'name',
				renderer: function (value, metaData, record, rowIndex, colIndex, store) {
					return '<i class="entity ' + record.data.entity + '"></i> ' + value;
				}
			},{
				id: 'id',
				header: t('ID'),
				width: dp(80),
				sortable: true,
				dataIndex: 'entityId',
				hidden: true
			}, {

				header: t('Type'),
				width: dp(80),
				sortable: false,
				dataIndex: 'entity',
				hidden: true
			},
			{
				id: 'modifiedAt',
				header: t('Modified at'),
				width: dp(160),
				sortable: true,
				dataIndex: 'modifiedAt',
				renderer: Ext.util.Format.dateRenderer(go.User.dateTimeFormat),
				hidden: true
			}
		];
		this.autoExpandColumn = 'name';
		this.store = new go.data.Store({			
			fields: ['id', 'entityId', 'entity', 'name', 'description', {name: 'modifiedAt', type: 'date'}],
			entityStore: "Search",
			sortInfo: {
				field: 'modifiedAt',
				direction: 'DESC'
			},
			baseParams: {
				filter: {}
			}
		});
		
		go.links.LinkGrid.superclass.initComponent.call(this);
	}
});