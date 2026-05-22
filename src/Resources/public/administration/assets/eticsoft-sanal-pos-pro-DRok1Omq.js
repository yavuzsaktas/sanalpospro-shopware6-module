const{Criteria:k}=Shopware.Data;Shopware.Component.register("sanalpospro-installment-list",{template:`
<sw-page class="sanalpospro-installment-list">
    <template #smart-bar-header>
        <h2>{{ $tc('sanalpospro-installment.list.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button
            variant="primary"
            :routerLink="{ name: 'sanalpospro.installment.create' }"
        >
            {{ $tc('sanalpospro-installment.list.buttonCreate') }}
        </sw-button>
    </template>

    <template #content>
        <sw-entity-listing
            v-if="items"
            :items="items"
            :repository="repository"
            :columns="columns"
            :isLoading="isLoading"
            :showSelection="true"
            :allowDelete="true"
            :allowEdit="true"
            detailRoute="sanalpospro.installment.detail"
            @delete-item="onDeleteItem"
        >
            <template #column-isActive="{ item }">
                <sw-icon
                    v-if="item.isActive"
                    name="regular-checkmark-xs"
                    small
                    color="#37d046"
                />
                <sw-icon
                    v-else
                    name="regular-times-xs"
                    small
                    color="#de294c"
                />
            </template>
        </sw-entity-listing>

        <sw-empty-state
            v-if="!isLoading && (!items || items.length === 0)"
            :title="$tc('sanalpospro-installment.list.title')"
        />
    </template>
</sw-page>
    `,inject:["repositoryFactory"],data(){return{items:null,isLoading:!1}},computed:{repository(){return this.repositoryFactory.create("sanalpospro_installment")},columns(){return[{property:"bankName",label:this.$tc("sanalpospro-installment.list.columnBankName"),allowResize:!0,primary:!0},{property:"cardType",label:this.$tc("sanalpospro-installment.list.columnCardType"),allowResize:!0},{property:"installmentCount",label:this.$tc("sanalpospro-installment.list.columnInstallmentCount"),allowResize:!0,align:"right"},{property:"interestRate",label:this.$tc("sanalpospro-installment.list.columnInterestRate"),allowResize:!0,align:"right"},{property:"isActive",label:this.$tc("sanalpospro-installment.list.columnIsActive"),allowResize:!0,align:"center"}]}},created(){this.loadItems()},methods:{loadItems(){this.isLoading=!0;const o=new k;o.setPage(1),o.setLimit(25),this.repository.search(o,Shopware.Context.api).then(s=>{this.items=s}).finally(()=>{this.isLoading=!1})},onDeleteItem(o){this.repository.delete(o.id,Shopware.Context.api).then(()=>{this.loadItems()})}}});Shopware.Component.register("sanalpospro-installment-detail",{template:`
<sw-page class="sanalpospro-installment-detail">
    <template #smart-bar-header>
        <h2 v-if="item && item.id">{{ $tc('sanalpospro-installment.detail.title') }}</h2>
        <h2 v-else>{{ $tc('sanalpospro-installment.detail.titleNew') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button
            variant="primary"
            :isLoading="isSaving"
            @click="onSave"
        >
            {{ $tc('global.default.save') }}
        </sw-button>
    </template>

    <template #content>
        <sw-card-view v-if="item">
            <sw-card :isLoading="isLoading">
                <sw-text-field
                    v-model="item.bankName"
                    :label="$tc('sanalpospro-installment.detail.labelBankName')"
                    :placeholder="$tc('sanalpospro-installment.detail.placeholderBankName')"
                    required
                />

                <sw-text-field
                    v-model="item.cardType"
                    :label="$tc('sanalpospro-installment.detail.labelCardType')"
                    :placeholder="$tc('sanalpospro-installment.detail.placeholderCardType')"
                />

                <sw-number-field
                    v-model="item.installmentCount"
                    :label="$tc('sanalpospro-installment.detail.labelInstallmentCount')"
                    :min="1"
                    :step="1"
                    required
                    numberType="int"
                />

                <sw-number-field
                    v-model="item.interestRate"
                    :label="$tc('sanalpospro-installment.detail.labelInterestRate')"
                    :min="0"
                    :step="0.01"
                    :digits="2"
                    numberType="float"
                />

                <sw-switch-field
                    v-model="item.isActive"
                    :label="$tc('sanalpospro-installment.detail.labelIsActive')"
                />
            </sw-card>
        </sw-card-view>
    </template>
</sw-page>
    `,inject:["repositoryFactory"],data(){return{item:null,isLoading:!1,isSaving:!1}},computed:{repository(){return this.repositoryFactory.create("sanalpospro_installment")}},created(){this.loadItem()},methods:{loadItem(){this.isLoading=!0,this.$route.params.id?this.repository.get(this.$route.params.id,Shopware.Context.api).then(o=>{this.item=o}).finally(()=>{this.isLoading=!1}):(this.item=this.repository.create(Shopware.Context.api),this.item.isActive=!0,this.item.interestRate=0,this.item.installmentCount=1,this.isLoading=!1)},onSave(){this.isSaving=!0,this.repository.save(this.item,Shopware.Context.api).then(()=>{this.isSaving=!1,Shopware.State.dispatch("notification/createNotification",{title:this.$tc("sanalpospro-installment.detail.title"),message:this.$tc("sanalpospro-installment.detail.messageSaveSuccess"),variant:"success"}),this.$router.push({name:"sanalpospro.installment.list"})}).catch(()=>{this.isSaving=!1,Shopware.State.dispatch("notification/createNotification",{title:this.$tc("sanalpospro-installment.detail.title"),message:this.$tc("sanalpospro-installment.detail.messageSaveError"),variant:"error"})})}}});const C={"sanalpospro-installment":{general:{title:"Ratenpläne",description:"Bank-Ratenpläne und Zinssätze verwalten"},list:{title:"Ratenpläne",columnBankName:"Bankname",columnCardType:"Kartentyp",columnInstallmentCount:"Ratenanzahl",columnInterestRate:"Zinssatz (%)",columnIsActive:"Aktiv",buttonCreate:"Ratenplan erstellen",deleteConfirmTitle:"Ratenplan löschen",deleteConfirmText:'Möchten Sie den Ratenplan für "{bankName}" wirklich löschen?'},detail:{title:"Ratenplan-Detail",titleNew:"Neuer Ratenplan",labelBankName:"Bankname",labelCardType:"Kartentyp",labelInstallmentCount:"Ratenanzahl",labelInterestRate:"Zinssatz (%)",labelIsActive:"Aktiv",placeholderBankName:"z.B. Garanti BBVA",placeholderCardType:"z.B. Visa, Mastercard",messageSaveSuccess:"Ratenplan erfolgreich gespeichert.",messageSaveError:"Ratenplan konnte nicht gespeichert werden."}}},P={"sanalpospro-installment":{general:{title:"Installment Plans",description:"Manage bank installment plans and interest rates"},list:{title:"Installment Plans",columnBankName:"Bank Name",columnCardType:"Card Type",columnInstallmentCount:"Installment Count",columnInterestRate:"Interest Rate (%)",columnIsActive:"Active",buttonCreate:"Create Installment Plan",deleteConfirmTitle:"Delete Installment Plan",deleteConfirmText:'Are you sure you want to delete the installment plan for "{bankName}"?'},detail:{title:"Installment Plan Detail",titleNew:"New Installment Plan",labelBankName:"Bank Name",labelCardType:"Card Type",labelInstallmentCount:"Installment Count",labelInterestRate:"Interest Rate (%)",labelIsActive:"Active",placeholderBankName:"e.g. Garanti BBVA",placeholderCardType:"e.g. Visa, Mastercard",messageSaveSuccess:"Installment plan saved successfully.",messageSaveError:"Could not save installment plan."}}};Shopware.Module.register("sanalpospro-installment",{type:"plugin",name:"sanalpospro-installment",title:"sanalpospro-installment.general.title",description:"sanalpospro-installment.general.description",color:"#1abc9c",icon:"regular-credit-card",snippets:{"de-DE":C,"en-GB":P},routes:{list:{component:"sanalpospro-installment-list",path:"list"},detail:{component:"sanalpospro-installment-detail",path:"detail/:id",meta:{parentPath:"sanalpospro.installment.list"}},create:{component:"sanalpospro-installment-detail",path:"create",meta:{parentPath:"sanalpospro.installment.list"}}}});const{Criteria:v}=Shopware.Data;Shopware.Component.register("sanalpospro-webhook-log-list",{template:`
<sw-page class="sanalpospro-webhook-log-list">
    <template #smart-bar-header>
        <h2>{{ $tc('sanalpospro-webhook-log.list.title') }}</h2>
    </template>

    <template #content>
        <sw-entity-listing
            v-if="items"
            :items="items"
            :repository="repository"
            :columns="columns"
            :isLoading="isLoading"
            :showSelection="false"
            :allowDelete="false"
            :allowEdit="false"
            :allowInlineEdit="false"
        >
            <template #column-status="{ item }">
                <sw-label
                    :variant="getStatusVariant(item.status)"
                    appearance="pill"
                    size="small"
                >
                    {{ item.status }}
                </sw-label>
            </template>
        </sw-entity-listing>

        <sw-empty-state
            v-if="!isLoading && (!items || items.length === 0)"
            :title="$tc('sanalpospro-webhook-log.list.title')"
        />
    </template>
</sw-page>
    `,inject:["repositoryFactory"],data(){return{items:null,isLoading:!1}},computed:{repository(){return this.repositoryFactory.create("sanalpospro_webhook_log")},columns(){return[{property:"createdAt",label:this.$tc("sanalpospro-webhook-log.list.columnCreatedAt"),allowResize:!0,primary:!0,sortable:!0},{property:"orderTxId",label:this.$tc("sanalpospro-webhook-log.list.columnOrderTxId"),allowResize:!0},{property:"paythorTxId",label:this.$tc("sanalpospro-webhook-log.list.columnPaythorTxId"),allowResize:!0},{property:"action",label:this.$tc("sanalpospro-webhook-log.list.columnAction"),allowResize:!0},{property:"status",label:this.$tc("sanalpospro-webhook-log.list.columnStatus"),allowResize:!0},{property:"amount",label:this.$tc("sanalpospro-webhook-log.list.columnAmount"),allowResize:!0,align:"right"},{property:"currency",label:this.$tc("sanalpospro-webhook-log.list.columnCurrency"),allowResize:!0}]}},created(){this.loadItems()},methods:{loadItems(){this.isLoading=!0;const o=new v;o.setPage(1),o.setLimit(25),o.addSorting(v.sort("createdAt","DESC")),this.repository.search(o,Shopware.Context.api).then(s=>{this.items=s}).finally(()=>{this.isLoading=!1})},getStatusVariant(o){return{approved:"success",success:"success",failed:"danger",pending:"warning",refunded:"info"}[o]||"neutral"}}});const R={"sanalpospro-webhook-log":{general:{title:"Webhook-Protokolle",description:"SanalPosPro Webhook-Transaktionsprotokolle anzeigen"},list:{title:"Webhook-Protokolle",columnCreatedAt:"Datum",columnOrderTxId:"Bestelltransaktions-ID",columnPaythorTxId:"PayThor-Transaktions-ID",columnAction:"Aktion",columnStatus:"Status",columnAmount:"Betrag",columnCurrency:"Währung"}}},T={"sanalpospro-webhook-log":{general:{title:"Webhook Logs",description:"View SanalPosPro webhook transaction logs"},list:{title:"Webhook Logs",columnCreatedAt:"Date",columnOrderTxId:"Order Transaction ID",columnPaythorTxId:"PayThor Transaction ID",columnAction:"Action",columnStatus:"Status",columnAmount:"Amount",columnCurrency:"Currency"}}};Shopware.Module.register("sanalpospro-webhook-log",{type:"plugin",name:"sanalpospro-webhook-log",title:"sanalpospro-webhook-log.general.title",description:"sanalpospro-webhook-log.general.description",color:"#e74c3c",icon:"regular-list",snippets:{"de-DE":R,"en-GB":T},routes:{list:{component:"sanalpospro-webhook-log-list",path:"list"}}});Shopware.Component.register("sanalpospro-connect-index",{template:`
        <sw-page class="sanalpospro-connect-index sanalpospro-single-scroll">
            <template #smart-bar-header>
                <h2>SanalPos Pro Management</h2>
            </template>
            <template #content>
                <div ref="reactContainer" class="sanalpospro-react-container"></div>
            </template>
        </sw-page>
    `,mounted(){if(!document.getElementById("sanalpospro-scroll-fix")){const o=document.createElement("style");o.id="sanalpospro-scroll-fix",o.textContent=`
                .sanalpospro-single-scroll .sw-page__content {
                    overflow: visible !important;
                    overflow-y: visible !important;
                    height: auto !important;
                    max-height: none !important;
                    position: static !important;
                }
                .sanalpospro-single-scroll .sw-card-view {
                    overflow: visible !important;
                    height: auto !important;
                }
                .sanalpospro-single-scroll .sw-page__main-content {
                    overflow: visible !important;
                    height: auto !important;
                    max-height: none !important;
                }
                .sanalpospro-react-container {
                    width: 100%;
                    min-height: 800px;
                    padding: 0;
                }
                .sanalpospro-react-container #root {
                    width: 100%;
                    min-height: 800px;
                    background: transparent;
                }
            `,document.head.appendChild(o)}this._resolvedAppId=106,this._fallbackAppIds=[],this._triedAppIds=new Set,this._recovering=!1,this.installRuntimeRecovery(),this.loadPayThorApp()},beforeDestroy(){this._runtimeErrorHandler&&(window.removeEventListener("error",this._runtimeErrorHandler,!0),this._runtimeErrorHandler=null),this._runtimeRejectionHandler&&(window.removeEventListener("unhandledrejection",this._runtimeRejectionHandler,!0),this._runtimeRejectionHandler=null),this.cleanupPayThorApp()},methods:{normalizeAppId(o){const s=Number.parseInt(o,10);return!Number.isInteger(s)||s<=0||s>1e3?null:s},buildAppCandidates(o){const s=[o,106,103].map(a=>this.normalizeAppId(a)).filter(a=>a!==null);return s.filter((a,n)=>s.indexOf(a)===n)},installRuntimeRecovery(){if(this._runtimeErrorHandler||this._runtimeRejectionHandler)return;const o=a=>{this._recovering=!0,console.warn("SanalPosPro: runtime id crash detected, retrying with fallback app_id",a),setTimeout(async()=>{try{await this.loadPayThorApp(a)}catch(n){console.error("SanalPosPro: fallback reload failed",n)}finally{this._recovering=!1}},50)},s=(a,n)=>{if(!(a.includes("reading 'id'")||a.includes('reading "id"'))||this._recovering)return;const p=Array.isArray(this._fallbackAppIds)?this._fallbackAppIds.shift():null;p&&(typeof(n==null?void 0:n.preventDefault)=="function"&&n.preventDefault(),o(p))};this._runtimeErrorHandler=a=>{var i;const n=String((a==null?void 0:a.message)||((i=a==null?void 0:a.error)==null?void 0:i.message)||"");s(n,a)},this._runtimeRejectionHandler=a=>{var p;const n=a==null?void 0:a.reason,i=String((n==null?void 0:n.message)||((p=n==null?void 0:n.toString)==null?void 0:p.call(n))||n||"");s(i,a)},window.addEventListener("error",this._runtimeErrorHandler,!0),window.addEventListener("unhandledrejection",this._runtimeRejectionHandler,!0)},async loadPayThorApp(o=null){if(this.cleanupPayThorApp(),this._createdRoot=!document.getElementById("root"),this._createdRoot){const r=document.createElement("div");r.id="root",this.$refs.reactContainer?this.$refs.reactContainer.appendChild(r):(r.style.cssText="position:fixed;top:130px;left:240px;right:0;bottom:0;z-index:10;background:#fff;overflow:auto;",document.body.appendChild(r))}let s="shopware",a="/sanalpospro/iapi/index",n=this.normalizeAppId(this._resolvedAppId)||106,i={order_status:"process",currency_convert:"no",showInstallmentsTabs:"no",paymentPageTheme:"modern"};try{const r=Shopware.Context.api.authToken&&Shopware.Context.api.authToken.access;if(r){const t=await fetch("/api/sanalpospro/admin-config",{headers:{Authorization:"Bearer "+r,Accept:"application/json"}});if(t.ok){const e=await t.json();s=e.xfvv||s,a=e.target_url||a;const l=this.normalizeAppId(e.app_id);l!==null&&(n=l),e.module_settings&&typeof e.module_settings=="object"&&(i=Object.assign(i,e.module_settings))}else console.error("SanalPosPro: Failed to fetch admin config",t.status)}else console.warn("SanalPosPro: no admin auth token available")}catch(r){console.error("SanalPosPro: Error fetching admin config",r)}const p=this.normalizeAppId(o)||n,y=this.buildAppCandidates(p);this._triedAppIds.add(p),this._fallbackAppIds=y.filter(r=>!this._triedAppIds.has(r));try{const r=String(p||106),t="paythor-connect-app-id",e=["etc-token","etc-user-level","etc-is-impersonating","etc-original-admin-token","etc-impersonate-token","paythor-merchant-app"];localStorage.removeItem("paythor-merchant-app"),localStorage.getItem(t)!==r&&(e.forEach(l=>localStorage.removeItem(l)),sessionStorage.clear(),localStorage.setItem(t,r))}catch(r){console.warn("SanalPosPro: LocalStorage access denied",r)}this._resolvedAppId=p,this._currentAppId=p;const g=`https://cdn.paythor.com/1/${p}/10.0.4`;window.xfvv=s,window.target_url=window.location.origin+a,window.store_url=window.location.origin,window.app_id=p,window.platform="shopware",window.program_id=1,window.style_url=`${g}/index.css`,window.generalSettings={order_status:{default_value:i.order_status,options:{process:"Processing"}},currency_convert:{default_value:i.currency_convert,options:{yes:"Yes",no:"No"}},showInstallmentsTabs:{default_value:i.showInstallmentsTabs,options:{yes:"Yes",no:"No"}},paymentPageTheme:{default_value:i.paymentPageTheme,options:{classic:"Classic",modern:"Modern"}}};const u=document.createElement("link");u.id="paythor-style",u.rel="stylesheet",u.href=window.style_url,document.head.appendChild(u);const c=document.createElement("script");c.id="paythor-script",c.type="module",c.src=`${g}/index.js?v=`+Date.now(),c.onerror=()=>console.error("[SanalPosPro] CDN script failed to load:",c.src),document.body.appendChild(c)},cleanupPayThorApp(){const o=document.getElementById("paythor-script");o&&o.remove();const s=document.getElementById("paythor-style");s&&s.remove();const a=document.getElementById("sanalpospro-scroll-fix");if(a&&a.remove(),this._createdRoot){const n=document.getElementById("root");n&&n.remove(),this._createdRoot=!1}else{const n=document.getElementById("root");n&&(n.innerHTML="")}}}});Shopware.Module.register("sanalpospro-connect",{type:"plugin",name:"sanalpospro-connect",title:"SanalPos Pro",description:"PayThor React CDN Application",color:"#1abc9c",icon:"regular-credit-card",routes:{index:{component:"sanalpospro-connect-index",path:"index"}},navigation:[{id:"sanalpospro-connect",label:"SanalPos Pro",color:"#1abc9c",icon:"regular-credit-card",parent:"sw-extension",position:10},{id:"sanalpospro-connect-index",label:"Account & Management",color:"#1abc9c",path:"sanalpospro.connect.index",icon:"regular-credit-card",parent:"sanalpospro-connect",position:10}]});(function(){const n="Shopware SanalPOS PRO!";function i(t){const e=Number.parseInt(t,10);return!Number.isInteger(e)||e<=0||e>1e3?null:e}function p(){const t=i(window.app_id);if(t!==null)return t;try{const e=i(localStorage.getItem("paythor-connect-app-id"));if(e!==null)return e}catch{}return 106}function y(t){try{return new URL(t,window.location.origin)}catch{return null}}function g(t){if(!t||typeof t!="object"||Array.isArray(t))return null;const e=Object.assign({},t),l=i(e.id),h=(typeof e.name=="string"?e.name.trim():"").toLowerCase();return e.app_id===void 0&&l!==null&&(e.app_id=l),e.appId===void 0&&l!==null&&(e.appId=l),(l===106||h.includes("swr")||h.includes("shopware"))&&(e.name=n,e.platform="shopware"),e}function u(t){return Array.isArray(t)?t.map(g).filter(Boolean):[]}async function c(t,e){try{const l=await t.clone().json();if(!l||typeof l!="object")return t;Array.isArray(l.data)||(l.data=[]),(e==="/app/list/all"||e==="/app/list/my")&&(l.data=u(l.data));const d=new Headers(t.headers);return d.set("Content-Type","application/json"),new Response(JSON.stringify(l),{status:t.status,statusText:t.statusText,headers:d})}catch{return t}}const r=window.fetch.bind(window);window.fetch=function(t,e){const l=typeof t=="string"?t:t&&t.url||"",d=y(l),h=d?d.hostname.toLowerCase():"",f=d?d.pathname:"",_=h.includes("paythor.com")||h.includes("sanalpospro.com"),S=h==="live-api.sanalpospro.com"&&(f==="/app/list/my"||f==="/app/list/all");if(!_||l.includes("cdn.paythor.com"))return r(t,e);e=e?Object.assign({},e):{};const w=p(),b=e.headers||{},I=b instanceof Headers?b:new Headers(b);if(I.set("etc-app-id",String(w)),I.set("ETC-APP-ID",String(w)),e.headers=I,e.body&&typeof e.body=="string")try{const m=JSON.parse(e.body);m.auth_query&&m.auth_query.app_id!==w&&(m.auth_query.app_id=w,e.body=JSON.stringify(m))}catch{}const A=r(t,e);return S?A.then(m=>c(m,f)):A}})();
//# sourceMappingURL=eticsoft-sanal-pos-pro-DRok1Omq.js.map
