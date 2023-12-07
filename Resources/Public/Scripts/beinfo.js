class BeInfo extends HTMLElement {
	constructor() {
		super();
	}

	connectedCallback() {
		// Create a shadow root
		const shadow = this.attachShadow({ mode: "open" });

		// Create wrapper
		const wrapper = document.createElement("div");
		wrapper.setAttribute("class", "wrapper");

		// Insert icon
		let imgUrl;
		if (this.hasAttribute("icon")) {
			const icon = document.createElement("div");
			icon.innerHTML = this.getAttribute("icon");
			icon.setAttribute("class", "icon");
			wrapper.appendChild(icon);
		}

		// Insert Text
		const info = document.createElement("div");
		info.setAttribute("class", "info");
		if (this.hasAttribute("text")) {
		 info.innerHTML = this.getAttribute("text");
		}
		wrapper.appendChild(info);


		// Insert Buttons
		const collectionNode = this.getAttribute("collectionNode");
		const referenceLanguage = this.getAttribute("referenceLanguage");

		const buttons = document.createElement("div");
		buttons.setAttribute("class", "buttons");
		info.setAttribute("class", "info");
		if (this.hasAttribute("showAddMissingButton")) {
			const button = document.createElement("a");
			button.textContent = "Translate missing contents"
			button.setAttribute("class", "button");
			buttons.appendChild(button);
			button.onclick = function(){
				window.parent.sitegeistLostInTranslationHostPlugin([
					{
						type: 'Sitegeist.LostInTranslation:AddMissingTranslations',
						subject: collectionNode,
						payload: {
							referenceLanguage: referenceLanguage
						}
					}
				]);
			};
		}
		if (this.hasAttribute("showUpdateOutdatedButton")) {
			const button = document.createElement("a");
			button.textContent = "Update outdated contents"
			button.setAttribute("class", "button");
			button.onclick = function(){
				window.parent.sitegeistLostInTranslationHostPlugin([
					{
						type: 'Sitegeist.LostInTranslation:UpdateOutdatedTranslations',
						subject: collectionNode,
						payload: {
							referenceLanguage: referenceLanguage
						}
					}
				]);
			};
			buttons.appendChild(button);
		}
		wrapper.appendChild(buttons);

		// CSS rules
		const style = document.createElement("style");
		style.textContent = `
			.wrapper {
				position: relative;
				background: rgb(34, 34, 34);
				color: white;
				padding: 20px;
				font-size: 1rem;
			}

			.icon {
				position: absolute;
				font-size: 3em;
			}

			.icon svg {
				fill: currentColor;
				height: 1em;
				width: auto;
			}

			.info {
				padding-left: 80px;
			}
			.info > :first-child {
				margin-top: 0;
			}
			.info > :last-child {
				margin-bottom: 0;
			}

			.buttons {
				text-align: right;
			}

			.button {
				margin-left: 20px;
				display: inline-block;
				background: rgb(50, 50, 50);
				text-decoration: none;
				color: white;
				line-height: 40px;
				padding: 0 16px;
				min-width: 40px;
				height: 40px;
			}

			.button:hover {
				background: rgb(0, 173, 238);
				cursor: pointer;
			}
		`;

		// Attach the created elements to the shadow dom
		shadow.appendChild(style);
		shadow.appendChild(wrapper);
	}
}

customElements.define("lost-in-translation-info", BeInfo);

