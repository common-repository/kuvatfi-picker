(function($) {
	var init = function() {
		if (typeof wp != "object" || wp == null || typeof wp.media != "function") {
			return false;
		}

		var kuvatfiFolders = "loading";

		var KuvatfiFilters = wp.media.view.AttachmentFilters.extend(
			{
				className: "attachment-kuvatfi-filters",
				id: "media-attachment-kuvatfi-filters",

				initialize: function() {
					this.createFilters();

					_.extend( this.filters, this.options.filters );

					this.$el.html(
						_.chain( this.filters ).map(
							function(filter, value) {
								return $( "<option></option>" ).val( value ).html( filter.text ).prop( "disabled", filter.disabled )[0];
							},
							this
						).value()
					);

					this.listenTo( this.model, "change", this.select );
					this.select();
				}
			}
		);

		var GalleryFilter = KuvatfiFilters.extend(
			{
				id: "media-attachment-kuvatfi-filters-gallery",

				createFilters: function() {
					var filters = [{
						text: "(select a gallery)",
						disabled: true
					}];

					if (kuvatfiFolders !== "loading") {
						$.each(
							kuvatfiFolders,
							function(gallery, folders) {
								filters.push(
									{
										text: gallery,
										props: {
											kuvatfi_host: gallery
										}
									}
								);
							}
						);
					}

					this.filters = filters;
				}
			}
		);

		var FolderFilter = KuvatfiFilters.extend(
			{
				id: "media-attachment-kuvatfi-filters-folder",

				createFilters: function() {
					var filters = [{
						text: "(select a folder)",
						disabled: true
					}];

					if (kuvatfiFolders !== "loading") {
						var folders = kuvatfiFolders[this.model.get( "kuvatfi_host" )];

						$.each(
							folders,
							function(path, fd) {
								filters.push(
									{
										text: path,
										props: {
											kuvatfi_folder: path
										}
									}
								);
							}
						);
					}

					this.filters = filters;
				}
			}
		);

		var KuvatfiBrowser = wp.media.view.AttachmentsBrowser.extend(
			{
				createUploader: function() {
					this.uploader = {
						$el: $( "#kuvatfi_uploader" ),
						hide: function() {}
					};

					return false;
				},

				createToolbar: function() {
					var toolbarOptions = {
						controller: this.controller,
						className: "media-toolbar media-toolbar-kuvatfi"
					};

					this.toolbar = new wp.media.view.Toolbar( toolbarOptions );
					this.toolbar.primary.$el.removeClass( "search-form" );

					this.views.add( this.toolbar );

					this.toolbar.set(
						"galleryFilterLabel",
						new wp.media.view.Label(
							{
								value: "Select your gallery",
								attributes: {
									"for": "media-attachment-kuvatfi-filters-gallery"
								},
								priority: -60
							}
						).render()
					);
					// galleryFilter here

					this.toolbar.set(
						"folderFilterLabel",
						new wp.media.view.Label(
							{
								value: "Select a folder",
								attributes: {
									"for": "media-attachment-kuvatfi-filters-folder"
								},
								priority: -40
							}
						).render()
					);
					// folderFilter here

					this.toolbar.set(
						"spinner",
						new wp.media.view.Spinner(
							{
								priority: -20
							}
						)
					);

					var self = this;

					this.toolbar.set(
						"refreshButton",
						new wp.media.view.Button(
							{
								text: "Refresh",
								className: "attachment-kuvatfi-refresh",
								priority: 20,
								controller: this.controller,
								click: function() {
									$.post(
										ajaxurl,
										{
											action: "kuvatfi_flush"
										}
									).done(
										function() {
											self.refreshData();
										}
									);
								}
							}
						).render()
					);
				},

				refreshData: function() {
					kuvatfiFolders = "loading";

					var self = this;

					var spinner = self.toolbar.get( "spinner" );
					spinner.show();

					self.collection.props.set( "kuvatfi_host", "" );
					self.collection.props.set( "kuvatfi_folder", "" );

					self.toolbar.unset( "galleryFilter" );
					self.toolbar.unset( "folderFilter" );

					var dfd = new $.Deferred();

					$.post(
						ajaxurl,
						{
							action: "kuvatfi_folders"
						}
					).done(
						function(res) {
							if (typeof res == "object" && res != null && res.success) {
								kuvatfiFolders = res.data;

								self.toolbar.set(
									"galleryFilter",
									new GalleryFilter(
										{
											controller: self.controller,
											model: self.collection.props,
											priority: -60
										}
									).render()
								);

								// Trigger done

								dfd.resolve();

								// Preset gallery if there's only one option or a valid gallery is saved from last time

								var galleries = [];

								$.each(
									kuvatfiFolders,
									function(g) {
										galleries.push( g );
									}
								);

								var lastGallery = false;

								if (typeof kuvatfi == "object" && kuvatfi != null && typeof kuvatfi.lastgallery == "string" && kuvatfi.lastgallery.length >= 5) {
									lastGallery = kuvatfi.lastgallery;
								}

								if (lastGallery !== false && galleries.indexOf( lastGallery ) > -1) {
									self.collection.props.set( "kuvatfi_host", lastGallery );
								} else if (galleries.length === 1) {
									self.collection.props.set( "kuvatfi_host", galleries[0] );
								}
							} else {
								dfd.reject( "ERR_NO_RES" );
							}
						}
					).fail(
						function(err) {
							dfd.reject( err );
						}
					).always(
						function() {
							spinner.hide();
						}
					);

					return dfd;
				}
			}
		);

		var extendOld = function(oldSelect) {
			return oldSelect.extend(
				{
					initialize: function() {
						oldSelect.prototype.initialize.apply( this, arguments );

						var self = this;

						this.on( "content:create:kuvatfi", this.kuvatfiContent, this );

						this.on(
							"toolbar:render:select toolbar:render:main-insert toolbar:render:featured-image",
							function(view) {
								var btn = view.get( "select" ) || view.get( "insert" );

								if (btn) {
									var oldClick = btn.options.click;

									btn.options.click = function() {
										if (self.content.mode() === "kuvatfi") {
											$( "body" ).append( '<div id="kuvatfi-loader" style=""><div class="kuvatfi-loader-c"><div class="kuvatfi-loader-i"><div class="kuvatfi-spinner"><div class="kuvatfi-double-bounce1"></div> <div class="kuvatfi-double-bounce2"></div></div></div></div></div>' );

											var state = self.state(),
											sel       = state.get( "selection" );

											var ids = [],
											host    = false;

											$.each(
												sel.models,
												function(i, img) {
													if (typeof img == "object" && img != null) {
														ids.push( img.attributes.id );

														if (host === false) {
															host = img.attributes.host;
														}
													}
												}
											);

											if (ids.length) {
												$.post(
													ajaxurl,
													{
														action: "kuvatfi_dl",
														host: host,
														ids: ids
													}
												).done(
													function(res) {
														if (typeof res == "object" && res != null && res.success) {
															sel.remove( sel.models );

															var querySaved = wp.media.query(
																{
																	post__in: res.data,
																	posts_per_page: -1
																}
															);

															querySaved.more().done(
																function() {
																	$.each(
																		querySaved.models,
																		function(i, att) {
																			sel.add( att );
																		}
																	);

																	oldClick.apply( self, arguments );

																	$( "#kuvatfi-loader" ).remove();
																}
															);
														}
													}
												);
											}
										} else {
											oldClick.apply( self, arguments );
										}
									};
								}
							},
							this
						);
					},

					browseRouter: function(routerView) {
						oldSelect.prototype.browseRouter( routerView );

						routerView.set(
							{
								kuvatfi: {
									text: "Kuvat.fi",
									priority: 60
								}
							}
						);
					},

					kuvatfiContent: function(contentRegion) {
						var state = this.state();

						this.$el.removeClass( "hide-toolbar" );

						var kuvatfiLib = new wp.media.model.Attachments(
							null,
							{
								props: {
									query: true,
									paged: 1,
									posts_per_page: -1, // Infinity,
									kuvatfi_host: "",
									kuvatfi_folder: ""
								}
							}
						);

						var browser = new KuvatfiBrowser(
							{
								controller: this,
								model: state,

								collection: kuvatfiLib,
								selection: state.get( "selection" ),
								sortable: false,
								search: false,
								filters: false,
								date: false,
								display: state.has( "display" ) ? state.get( "display" ) : state.get( "displaySettings" ),
								dragInfo: false,
								suggestedWidth: false,
								suggestedHeight: false,
								idealColumnWidth: state.get( "idealColumnWidth" ),

								AttachmentView: state.get( "AttachmentView" )
							}
						);

						contentRegion.view = browser;

						browser.refreshData().done(
							function() {
								browser.collection.props.on(
									"change:kuvatfi_host",
									function() {
										browser.toolbar.set(
											"folderFilter",
											new FolderFilter(
												{
													controller: browser.controller,
													model: browser.collection.props,
													priority: -40
												}
											).render()
										);
									},
									browser
								);
							}
						);
					}
				}
			);
		};

		wp.media.view.MediaFrame.Select = extendOld( wp.media.view.MediaFrame.Select );
		wp.media.view.MediaFrame.Post   = extendOld( wp.media.view.MediaFrame.Post );
	};

	(typeof wp !== "undefined" && typeof wp.domReady === "function" ? wp.domReady( init ) : $( document ).ready( init ));
})( jQuery );
