export default class LiteSAPHandler {
  constructor({ baseUrl, rootSelector, handleForms = true }) {
    this.baseUrl = baseUrl;
    this.rootSelector = rootSelector;
    this.handleForms = handleForms;
    this.cachedScripts = new Set();
    this.cachedStyles = new Set();
    this.isLoading = false;

    // Initialize on DOMContentLoaded
    document.addEventListener("DOMContentLoaded", () => {
      this.cacheOnPageLoad();
      this.addListeners();
    });
  }

  cacheOnPageLoad() {
    document
      .querySelectorAll("script[src], link[rel='stylesheet']")
      .forEach((el) => {
        if (el.tagName === "SCRIPT" && el.src) {
          this.cachedScripts.add(el.src);
        } else if (el.tagName === "LINK" && el.href) {
          this.cachedStyles.add(el.href);
        }
      });
  }

  pageNotFound(errorMessage) {
    return `
          <div class="page-not-found">
            <div style="text-align: center; margin-top: 50px; font-family: Arial, sans-serif;">
              <h1 style="color: red;">404 - Page Not Found</h1>
              <p style="color: #333; font-size: 18px;">
                ${
                  errorMessage || "The page you are looking for does not exist."
                }
              </p>
              <a href="${
                this.baseUrl
              }" style="color: blue; text-decoration: underline;">Go Back Home</a>
            </div>
          </div>`;
  }

  showLoading() {
    if (!this.isLoading) {
      const loader = document.createElement("div");
      loader.id = "loading-indicator";
      loader.style = `
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-size: 18px; font-family: Arial, sans-serif; color: #555;
          `;
      loader.textContent = "Loading...";
      document.body.appendChild(loader);
      this.isLoading = true;
    }
  }

  hideLoading() {
    const loader = document.getElementById("loading-indicator");
    if (loader) loader.remove();
    this.isLoading = false;
  }

  cleanupOldAssets() {
    document
      .querySelectorAll("script:not([main])")
      .forEach((script) => script.remove());
    document.querySelectorAll("link[rel='stylesheet']").forEach((link) => {
      if (!this.cachedStyles.has(link.href)) link.remove();
    });
  }

  loadScripts(scripts) {
    scripts.forEach((script) => {
      const src = script.getAttribute("src");

      if (src && !this.cachedScripts.has(src)) {
        const newScript = document.createElement("script");
        newScript.src = src;
        newScript.defer = true;
        this.cachedScripts.add(src);
        document.body.appendChild(newScript);
      } else if (!src && !this.cachedScripts.has(script.textContent)) {
        const inlineScript = document.createElement("script");
        inlineScript.textContent = script.textContent;
        this.cachedScripts.add(script.textContent);
        document.body.appendChild(inlineScript);
      }
    });
  }

  loadStyles(styles) {
    styles.forEach((style) => {
      const href = style.getAttribute("href");

      if (href && !this.cachedStyles.has(href)) {
        // External stylesheet (link tag)
        const newLink = document.createElement("link");
        newLink.rel = "stylesheet";
        newLink.href = href;
        this.cachedStyles.add(href);
        document.head.appendChild(newLink);
      } else if (!href) {
        // Inline styles (style tag)
        const newStyle = document.createElement("style");
        newStyle.textContent = style.textContent;
        console.log(style.textContent);
        document.head.appendChild(newStyle);
      }
    });
  }

  updateDocumentProps(newDoc) {
    const newTitle = newDoc.querySelector("title");
    if (newTitle) {
      document.title = newTitle.textContent;
    }

    const newMetaTags = newDoc.querySelectorAll("meta");
    const currentMetaTags = new Map();
    document.querySelectorAll("meta").forEach((meta) => {
      const name = meta.getAttribute("name") || meta.getAttribute("property");
      if (name) currentMetaTags.set(name, meta);
    });

    newMetaTags.forEach((meta) => {
      const name = meta.getAttribute("name") || meta.getAttribute("property");
      if (!currentMetaTags.has(name)) {
        document.head.appendChild(meta.cloneNode(true));
      }
    });

    const newStyles = newDoc.querySelectorAll("link[rel='stylesheet']");
    this.cleanupOldAssets();
    this.loadStyles(newStyles);
    const _styles = newDoc.querySelectorAll("style");
    this.loadStyles(_styles);
  }

  render(url, formData = null, method = "GET") {
    window.history.pushState("", "", url);
    if (this.isLoading) return;
    this.showLoading();

    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);

    if (formData) {
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    }

    xhr.onload = () => {
      this.hideLoading();
      if (xhr.status >= 200 && xhr.status < 300) {
        const parser = new DOMParser();
        const newDoc = parser.parseFromString(xhr.responseText, "text/html");
        const newContent = newDoc.querySelector(this.rootSelector);
        const scripts = newDoc.querySelectorAll("script");
        const styles = newDoc.querySelectorAll("link[rel='stylesheet']");

        this.cleanupOldAssets();
        this.loadScripts(scripts);
        this.loadStyles(styles);

        const root = document.querySelector(this.rootSelector);
        if (root && newContent) {
          root.innerHTML = "";
          while (newContent.firstChild) {
            root.appendChild(newContent.firstChild);
          }
        } else if (root) {
          root.innerHTML = this.pageNotFound(
            "The requested content could not be loaded."
          );
        }

        this.updateDocumentProps(newDoc);
      } else {
        console.error(`Error ${xhr.status}: ${xhr.statusText}`);
        const root = document.querySelector(this.rootSelector);
        if (root) {
          root.innerHTML = this.pageNotFound(
            `Error ${xhr.status}: Failed to load the page.`
          );
        }
      }
    };

    xhr.onerror = () => {
      this.hideLoading();
      console.error("Network error occurred.");
      const root = document.querySelector(this.rootSelector);
      if (root) {
        root.innerHTML = this.pageNotFound(
          "Network error. Please try again later."
        );
      }
    };

    if (formData) {
      xhr.send(formData);
    } else {
      xhr.send();
    }
  }

  addListeners() {
    window.addEventListener("popstate", () => {
      this.render(window.location.href);
    });

    document.addEventListener("click", (event) => {
      const link = event.target.closest("a");
      if (link && link.href) {
        if (!link.hasAttribute("redirect")) {
          event.preventDefault();
          this.render(link.href);
        }
      }
    });

    if (this.handleForms) {
      document.addEventListener("submit", (event) => {
        const form = event.target.closest("form");
        if (form) {
          event.preventDefault();
          const formData = new FormData(form);
          const formAction =
            form.getAttribute("action") || window.location.href;
          const formMethod =
            form.getAttribute("method")?.toUpperCase() || "GET";

          if (formMethod === "GET") {
            const queryParams = new URLSearchParams(formData).toString();
            const urlWithParams = `${formAction}?${queryParams}`;
            this.render(urlWithParams);
          } else if (formMethod === "POST") {
            const formBody = new URLSearchParams(formData).toString();
            this.render(formAction, formBody, "POST");
          }
        }
      });
    }
  }
}
