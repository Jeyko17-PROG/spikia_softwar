#include "SpikiaSignAvatar.h"
#include "WebSocketsModule.h"

#define LOCTEXT_NAMESPACE "FSpikiaSignAvatarModule"

void FSpikiaSignAvatarModule::StartupModule()
{
	// FWebSocketsModule::Get() ya lo hace lazy en la mayoria de los casos, pero forzar la
	// carga aca (LoadModuleChecked) garantiza que IWebSocketModule::Get() en
	// SpikiaSignWebSocketClient.cpp nunca falle por orden de carga de modulos.
	FModuleManager::Get().LoadModuleChecked<FWebSocketsModule>("WebSockets");
}

void FSpikiaSignAvatarModule::ShutdownModule()
{
}

#undef LOCTEXT_NAMESPACE

IMPLEMENT_MODULE(FSpikiaSignAvatarModule, SpikiaSignAvatar)
