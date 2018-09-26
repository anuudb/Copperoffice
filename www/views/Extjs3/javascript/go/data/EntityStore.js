
/* global Ext, go */

/**
 * Entity store
 * 
 * The entity store is a single source of truth for all the entities.
 * It's kept up to date with flux when go.Jmap.request() calls are made.
 * Then it fires a "changes" event that other view stores or components can
 * subscribe to. The changes event will fire at the end of an event cycle.
 * It will pass:
 * 
 * store: the entity store
 * added: int[]|string[] array of ids's
 * changed: int[]|string[] array of ids's
 * detroyed: int[]|string[] array of ids's
 * 
 */
go.data.EntityStore = Ext.extend(go.flux.Store, {

	state: null,
	
	data : null,
	
	notFound: null,

	entity: null,	
	
	changes : null,
	
	constructor : function(config) {
		go.data.EntityStore.superclass.constructor.call(this, config);
		
		this.addEvents({changes:true, error:true});
		
		this.notFound = [];
		this.data = {};
		this.state = null;
		
		this.restoreState();
		this.initChanges();		
	},
	
	initChanges : function() {
		this.changes = {
			added: [],
			changed: [],
			destroyed: []
		};
	},
	
	restoreState : function() {
//		if(!window.localStorage.entityStores) {
//			window.localStorage.entityStores = {};
//		}
//		
//		var json = window.localStorage["entityStore-" + this.entity.name];		
//		if(json) {
//			var state = JSON.parse(json);			
//			this.data = state.data;
//			this.state = state.state;		
//			this.notFound = state.notFound;
//		}
	},
	
	saveState : function() {		
//		var state = JSON.stringify({
//			state: this.state,
//			data: this.data,
//			notFound: this.notFound
//		});
//		
//		if(!window.localStorage.entityStores) {
//			window.localStorage.entityStores = {};
//		}		
//		window.localStorage["entityStore-" + this.entity.name] = state;		
	},
	
	
	_add : function(entity) {
		
		if(!entity.id) {
			console.error(entity);
			throw "Entity doesn't have an 'id' property";
		}
		
		if(this.data[entity.id]) {
			this.changes.changed.push(entity.id);
		} else
		{
			this.changes.added.push(entity.id);
		}		
		this.data[entity.id] = entity;
		
		//remove from not found.
		var i = this.notFound.indexOf(entity.id);
		if(i > -1) {
			this.notFound.splice(i, 1);
		}
		
		this._fireChanges();
	},
	
	_destroy : function(id) {		
		delete this.data[id];
		this.changes.destroyed.push(id);
		this._fireChanges();
	},
	
	_fireChanges : function() {
		var me = this;

		if (me.timeout) {
			clearTimeout(me.timeout);
		}
		
		//delay fireevent one event loop cycle
		me.timeout = setTimeout(function () {				
			me.fireEvent('changes', me, me.changes.added, me.changes.changed, me.changes.destroyed);			
			me.initChanges();
			me.timeout = null;
		}, 0);
	},


	receive: function (action) {		

		switch (action.type) {
			case this.entity.name + "/get":
				
				// If no items are available, don't continue
				if(!action.payload.list){
					return;
				}
				
				//add data from get response
				for(var i = 0,l = action.payload.list.length;i < l; i++) {
					this._add(action.payload.list[i]);
				};
				this.state = action.payload.state;
				this.saveState();			
				
				break;

			case this.entity.name + "/query":
				//if a list call was made then fetch updates if state mismatch
				if (this.state && action.payload.state !== this.state) {
					this.getUpdates();
				} else
				{
					this.state = action.payload.state;
				}
				break;

			case this.entity.name + "/set":
				//update state from set we initiated
				this.state = action.payload.newState;
				break;
		}
	},

	getUpdates: function (cb, scope) {		
		
		if (this.state) {
			var clientCallId = go.Jmap.request({
				method: this.entity.name + "/getUpdates",
				params: {
					sinceState: this.state
				},
				callback: function(options, success, response) {
					if(success) {
						this.state = response.newState;
					} else
					{
						this.state = null;
						this.data = {};						
					}
					
					this.saveState();
				},
				scope: this
			});

			go.Jmap.request({
				method: this.entity.name + "/get",
				params: {
					"#ids": {
						resultOf: clientCallId,
						path: '/changed'
					}
				},
				callback: function(options, success, response) {					
					if(cb) {
						cb.call(scope || this, this);
					}
				},
				scope: this
			});
		} else
		{
			go.Jmap.request({
				method: this.entity.name + "/get",
				callback: function (options, success, response) {
					
					this.state = response.state;
					this.saveState();
					if(cb) {
						cb.call(scope || this, this);
					}
				},
				scope: this
			});
		}

	},

	/**
	 * Get entities
	 * 
	 * @param {array} ids
	 * @param {function} cb called with "entitiies[]" and boolean "async"
	 * @param {object} scope
	 */
	get: function (ids, cb, scope) {

		if(!ids){
			ids = [];
		}
		
		if(!Ext.isArray(ids)) {
			throw "ids must be an array";
		}

		var entities = [], unknownIds = [];

		for (var i = 0; i < ids.length; i++) {
			var id = ids[i];
			if(!id) {
				throw "Empty ID passed to EntityStore.get()";
			}
			if(this.data[id]) {
				entities.push(this.data[id]);
			} else if(this.notFound.indexOf(id) > -1) {
				//entities.push(null);
			} else
			{
				unknownIds.push(id);
			}			
		}
		if (unknownIds.length) {
			go.Jmap.request({
				method: this.entity.name + "/get",
				params: {
					ids: unknownIds
				},
				callback: function (options, success, response) {
					if(!success) {
						return;
					}
					
					if(!go.util.empty(response.notFound)) {
						this.notFound = this.notFound.concat(response.notFound);
						console.log("Item not found", response);						
					}
					
					this.state = response.state;
					this.saveState();
					this.get(ids, cb, scope); //passed hidden 4th argument to pass to the callback to track that it was asynchronously called					
				},
				scope: this
			});
			return false;
		} 
		
		if(cb) {		
			cb.call(scope || this, entities, this);			
		}
	},
	
	findBy : function(fn, scope, startIndex) {
		startIndex = startIndex || 0;
		var data = Object.values(this.data);
		for(var i = startIndex, l = data.length; i < l; i++) {
			if(fn.call(scope || this, data[i])) {
				return data[i];
			}
		}
	},

	/**
	 * Create or update entities
	 * 
	 * 
	 * ```
	 * var update = {};
		update[this.moreMenu.record.id] = {enabled: !this.moreMenu.record.data.enabled};
				
	 * go.Stores.get("Foo").set({
	 *		create: {"client-id-1" : {name: "test"}},
	 *		update: update,
	 *		destroy: [2]
	 *	}, function(options, success, response){}, this);
	 * 
	 * ```
	 * 
	 * Destroy:
	 * 
	 * ```
	 * this.entityStore.set({destroy: [1,2]}, function (options, success, response) {
			if (response.destroyed) {
				this.hide();
			}
		}, this);
		```
	 * 
	 * @param {object} params	 
	 * @param {function} cb A function called with success, values, response, options
	 * @param {object} scope
	 * 	 
	 * @returns {string} Client request ID
	 * 
	 * @link http://jmap.io/spec-core.html#/set
	 */
	set: function (params, cb, scope) {
		
		//params.ifInState = this.state;
		
		if(params.create && Ext.isArray(params.create)) {
			throw "'create' must be an object with client ID's as key. Not an array.";
		}
		
		if(params.update && Ext.isArray(params.update)) {
			throw "'update' must be an object with client ID's as key. Not an array.";
		}
		
		if(params.destroy && !Ext.isArray(params.destroy)) 
		{
			throw "'destroy' must be an array.";
		}

		return go.Jmap.request({
			method: this.entity.name + "/set",
			params: params,
			scope: this,
			callback: function (options, success, response) {
				
				if(!success) {
					this.fireEvent("error", options, response);
					return;
				}
				
				var entity, clientId;
				
				if(response.created) {
					for(clientId in response.created) {
						//merge client data with server defaults.
						entity = Ext.apply(params.create[clientId], response.created[clientId]);						
						this._add(entity);
					}
				}
				
				if(response.updated) {
					for(var serverId in response.updated) {
						//merge existing data, with updates from client and server
						entity = Ext.apply(this.data[serverId], params.update[serverId], response.updated[serverId]);					
						console.log(entity);
						this._add(entity);
					}
				}
				
				this.state = response.newState;	
				this.saveState();
				
				if(response.destroyed) {
					for(var i =0, l = response.destroyed.length; i < l; i++) {						
						this._destroy(response.destroyed[i]);
					}
				}

				if(cb) {
					cb.call(scope || this, options, success, response);
				}

			}
		});
	},
	
	/**
	 * Query the API for a sorted / filtered list of entity id's
	 * 
	 * @param {object} params
	 * @param {function} callback
	 * @param {object} scope
	 * @returns {String} Client call ID
	 */
	query : function(params, callback, scope) {
		return go.Jmap.request({
			method: this.entity.name + "/query",
			params: params,
			callback: callback,
			scope: scope || this
		});
	}
});
