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
			const icon = document.createElement("span");
			icon.innerHTML = this.getAttribute("icon");
			icon.setAttribute("class", "icon");
			wrapper.appendChild(icon);
		}

		// Insert Text
		const info = document.createElement("span");
		info.setAttribute("class", "info");
		if (this.hasAttribute("text")) {
		 info.innerHTML = this.getAttribute("text");
		}
		wrapper.appendChild(info);

		// Insert Buttons
		const buttons = document.createElement("div");
		buttons.setAttribute("class", "buttons");
		info.setAttribute("class", "info");
		if (this.hasAttribute("addMissingHref")) {
			const button = document.createElement("a");
			button.textContent = "Translate missing contents"
			button.setAttribute("class", "button");
			button.setAttribute("href", this.getAttribute("addMissingHref"));
			buttons.appendChild(button);
		}
		if (this.hasAttribute("updateOutdatedHref")) {
			const button = document.createElement("a");
			button.textContent = "Update outdated contents"
			 button.setAttribute("class", "button");
			button.setAttribute("href", this.getAttribute("updateOutdatedHref"));
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

			.buttons {
				text-align: right;
			}

			.button {
				display: inline-block;
				background: #00a338;
				text-decoration: none;
				color: white;
				line-height: 40px;
				padding: 0 16px;
				min-width: 40px;
				height: 40px;
			}
		`;

		// Attach the created elements to the shadow dom
		shadow.appendChild(style);
		shadow.appendChild(wrapper);
	}
}

customElements.define("lost-in-translation-info", BeInfo);

